<?php

namespace App\Model;

use Nette\Database\Context;
use Nette\Http\FileUpload;
use Nette\Utils\Image;
use Nette\Utils\Random;
use Nette\Utils\Strings;

class KufrGameModel {

	const COLS = 6;
	const ROWS = 4;

	const RAND_IMAGES_IN_ONE_GAME = 5;

	const RESULT_CORRECT = "correct";
	const RESULT_CORRECT_TEXTS = [
		"Správně",
		"Výborně",
		"Trefa",
		"ANO!",
		"Super",
		"Dobře ty!",
	];

	const RESULT_ALMOST = "almost";
	const RESULT_ALMOST_TEXTS = [
		"Zkus to ještě jednou",
		"Vloudila se ti tam chybička",
		"Skoro",
		"Ještě to úplně nesedí",
		"Těsně vedle",
	];

	const RESULT_WRONG = "wrong";
	const RESULT_WRONG_TEXTS = [
		"Špatně",
		"Tak to není",
		"Bohužel nic",
		"Je mi líto, tak ne",
		"Nene",
		"Nee",
	];

	const PENALTY_POINT_GUESS = 1;
	const PENALTY_POINT_UNHIDE = 2;
	const PENALTY_POINT_USED_HINT = 5;

	/** @var string */
	protected $imagePath;

	/** @var Context */
	protected $db;

	public function __construct($imagePath, Context $db) {
		$this->imagePath = $imagePath;
		$this->db = $db;
	}

	public static function countPoints(int $guesses, int $unhide, bool $usedHint) {
		return max(0, $guesses - 1) * self::PENALTY_POINT_GUESS
			+ max(0, $unhide - 1) * self::PENALTY_POINT_UNHIDE
			+ $usedHint * self::PENALTY_POINT_USED_HINT;
	}

	public static function getResultText($result) {
		$list = [];
		switch ($result) {
			case self::RESULT_ALMOST:
				$list = self::RESULT_ALMOST_TEXTS;
				break;
			case self::RESULT_CORRECT:
				$list = self::RESULT_CORRECT_TEXTS;
				break;
			case self::RESULT_WRONG:
				$list = self::RESULT_WRONG_TEXTS;
				break;
		}

		return $list[rand(0, count($list) - 1)] ?? null;

	}

	public function fetch($id) {

		return $this->db
			->table(Table::KUFR_GAME_THINGS)
			->where("id", $id)
			->fetch();

	}

	public function fetchAll() {

		return $this->db
			->table(Table::KUFR_GAME_THINGS)
			->order('id')
			->fetchAll();

	}

	public function fetchRand($count) {
		return $this->db
			->table(Table::KUFR_GAME_THINGS)
			->order('RAND()')
			->limit($count)
			->fetchAll();
	}

	public function fetchTopGames($top) {
		return $this->db
			->table(Table::KUFR_GAME)
			->where('result_points IS NOT NULL')
			->order('result_points ASC')
			->limit($top)
			->fetchAll();
	}

	public function getCurrentLevelNumber($slug) {
		$game = $this->db
			->table(Table::KUFR_GAME)
			->where('slug', $slug)
			->fetch();

		$levelsCount = $this->db
			->table(Table::KUFR_GAME_LEVELS)
			->select('COUNT(id) AS currLevelNumber')
			->where('game_id', $game['id'])
			->where('done', '1')
			->fetch();

		return $levelsCount['currLevelNumber'] + 1;

	}

	/**
	 * @param $userId
	 * @return string Game slug
	 */
	public function createGame(int $userId): string {

		$slug = Random::generate(12);
		$this->db->beginTransaction();
		$row = $this->db
			->table(Table::KUFR_GAME)
			->insert([
				"user_id" => $userId,
				"slug" => $slug,
				"start_time" => new \DateTime(),
				"result_points" => null,
			]);

		$things = $this->fetchRand(self::RAND_IMAGES_IN_ONE_GAME);

		foreach ($things as $thing) {
			$this->db
				->table(Table::KUFR_GAME_LEVELS)
				->insert([
					"game_id" => $row['id'],
					"thing_id" => $thing['id'],
				]);
		}

		$this->db->commit();

		return $slug;

	}

	public function loadGame($slug) {

		$game = $this->db
			->table(Table::KUFR_GAME)
			->where('slug', $slug)
			->fetch();

		$level = $this->db
			->table(Table::KUFR_GAME_LEVELS)
			->where("game_id", $game['id'])
			->where('done', 0)
			->order('id')
			->limit(1)
			->fetch();

		if (!$level) {
			return [
				'game' => $game,
				'level' => $level,
			];
		}

		$thing = $level->ref(Table::KUFR_GAME_THINGS, 'thing_id');

		$level = iterator_to_array($level);
		$level['unhide'] = (bool) $level['unhide'] ? json_decode($level['unhide'], true) : [];

		return [
			'game' => $game,
			'level' => $level,
			'currentThing' => $thing,
		];

	}

	public function loadImage($slug, $x, $y) {

		$pos = "{$x}x{$y}";
		$res = $this->loadGame($slug);

		if (in_array($pos, $res['level']['unhide'])) {
			$path = $this->imagePath . $res['currentThing']['path'] . "/{$pos}.jpg";
			return Image::fromFile($path);
		}

		return Image::fromBlank(200, 200, Image::rgb(125, 125, 125));

	}

	public function unhide($slug, $x, $y) {

		/** TODO: check for dimensions */
		$res = $this->loadGame($slug);
		$unhide = $res['level']['unhide'];
		$unhide[] = $x . "x" . $y;
		$this->db
			->table(Table::KUFR_GAME_LEVELS)
			->where("id", $res['level']['id'])
			->update([
				"unhide" => json_encode($unhide),
			]);

	}

	public function setDone($slug) {

		$game = $this->loadGame($slug);
		$level = $game['level'];

		if ($level['points'] !== null) {
			$this->updateLevel([
				"id" => $level['id'],
				"done" => "1",
			]);
		}

	}

	public function tryGuess($slug, $guess) {

		$game = $this->loadGame($slug);

		$thing = $game['currentThing'];
		$level = $game['level'];

		$res = levenshtein(Strings::webalize($thing['name']), Strings::webalize($guess));

		if ($res == 0) {
			// Good guess
			$points = self::countPoints($level['guesses'] + 1, count($level['unhide']), $level['used_hint']);
			$this->updateLevel([
				"id" => $level['id'],
				"guesses" => $level['guesses'] + 1,
				"points" => $points,
			]);

			return self::RESULT_CORRECT;

		} else if ($res == 1) {
			// allmost, show text almost and dont save guess
			return self::RESULT_ALMOST;
		} else {
			// Wrong guess
			$this->updateLevel([
				"id" => $level['id'],
				"guesses" => $level['guesses'] + 1,
			]);

			return self::RESULT_WRONG;
		}

	}

	public function getResults($slug) {

		$game = $this->db
			->table(Table::KUFR_GAME)
			->where('slug', $slug)
			->fetch();
		$game = iterator_to_array($game);

		$levels = $this->db
			->table(Table::KUFR_GAME_LEVELS)
			->where("game_id", $game['id'])
			->order('id ASC')
			->fetchAll();

		$levels = array_map(function ($row) {
			$row = iterator_to_array($row);
			$row['unhide'] = (bool) $row['unhide'] ? json_decode($row['unhide'], true) : [];
			return $row;
		}, $levels);

		$thingsIds = array_column($levels, "thing_id");

		$things = $this->db
			->table(Table::KUFR_GAME_THINGS)
			->where("id", $thingsIds)
			->fetchAll();

		if (!$game['result_points']) {

			$sum = array_sum(array_column($levels, "points"));

			$this->db
				->table(Table::KUFR_GAME)
				->where('id', $game['id'])
				->update([
					"result_points" => $sum,
				]);
			$game['result_points'] = $sum;

		}

		return [
			"game" => $game,
			"levels" => $levels,
			"things" => $things,
		];

	}

	public function updateLevel($data) {

		$id = $data['id'];
		unset($data['id']);
		return $this->db
			->table(Table::KUFR_GAME_LEVELS)
			->where("id", $id)
			->update($data);

	}

	public function saveThing($data) {

		if ($data['image'] instanceof FileUpload && $data['image']->isImage() && $data['image']->isOk()) {

			$image = Image::fromFile($data['image']);
			$image->resize(1200, 800, Image::EXACT);

			$path = Strings::webalize($data['name']);
			$dir = $this->imagePath . $path;
			@mkdir($dir);
			$image->save($dir . "/original_image.jpg", 90, Image::JPEG);

			$w = 1200 / self::COLS;
			$h = 800 / self::ROWS;
			for ($row = 0; $row < self::ROWS; $row++) {
				for ($col = 0; $col < self::COLS; $col++) {
					$i = clone $image;
					$i->crop($col * $w, $row * $h, $w, $h);
					$i->save($dir . "/{$col}x{$row}.jpg", 90, Image::JPEG);
				}
			}
			$data['path'] = $path;

		}
		unset($data['image']);

		if (isset($data['id'])) {
			return $this->db
				->table(Table::KUFR_GAME_THINGS)
				->where('id', $data['id'])
				->update($data);
		} else {
			return $this->db
				->table(Table::KUFR_GAME_THINGS)
				->insert($data);
		}

	}

	public function delete($id) {

		/** TODO: remove images from HDD */
		$this->db
			->table(Table::KUFR_GAME_THINGS)
			->where("id", $id)
			->delete();

	}

	public function useHint($slug) {

		$game = $this->loadGame($slug);
		$this->db
			->table(Table::KUFR_GAME_LEVELS)
			->where('id', $game['level']['id'])
			->update([
				"used_hint" => 1,
			]);

	}

}

class GameFinishedException extends \Exception {
}