<?php

namespace App\Presenters;

use App\Model\GameFinishedException;
use App\Model\KufrGameModel;
use Nette;

class HomepagePresenter extends Nette\Application\UI\Presenter {

	/** @var KufrGameModel */
	protected $kufrGameModel;

	/** @var string */
	protected $result;

	public function __construct(KufrGameModel $kufrGameModel) {
		$this->kufrGameModel = $kufrGameModel;
	}

	public function renderDefault() {

		$this->template->games = $this->kufrGameModel->fetchTopGames(10);

	}

	public function renderGame($slug = null) {

		/** TODO: fix userID */
		$userId = 1;
		if (!$slug) {

			// Create new game
			$slug = $this->kufrGameModel->createGame($userId);
			$this->redirect("game", ["slug" => $slug]);

		}

		$res = $this->kufrGameModel->loadGame($slug);

		if (!$res['level']) {
			$this->redirect('results', ['slug' => $slug]);
		} else {

			// Load current level
			$this->template->slug = $slug;
			$this->template->level = $res['level'];
			$this->template->thing = $res['currentThing'];
			$this->template->levelsPerGame = KufrGameModel::RAND_IMAGES_IN_ONE_GAME;
			$this->template->currLevel = $this->kufrGameModel->getCurrentLevelNumber($slug);
			$this->template->result = $this->result;
			$this->template->points = $this->kufrGameModel::countPoints($res['level']['guesses'], count($res['level']['unhide']), $res['level']['used_hint']);

			$this['guessForm']['slug']->setValue($slug);
			$this['guessForm']['guess']->setValue("");

		}

	}

	public function renderResults($slug) {
		$results = $this->kufrGameModel->getResults($slug);
		$this->template->game = $results['game'];
		$this->template->levels = $results['levels'];
		$this->template->things = $results['things'];
	}

	public function renderAdmin() {
		$this->template->things = $this->kufrGameModel->fetchAll();
	}

	public function renderAddThing() {
		$this->template->setFile(__DIR__ . "/../templates/Homepage/editThing.latte");
	}

	public function renderEditThing($id) {
		$this['editThing']->setDefaults($this->kufrGameModel->fetch($id));
	}

	public function actionDeleteThing($id) {

		$this->kufrGameModel->delete($id);
		$this->flashMessage("Smazáno");
		$this->redirect("admin");

	}

	public function actionNextLevel($slug) {
		$this->kufrGameModel->setDone($slug);
		$this->redirect('game', ['slug' => $slug]);
	}

	public function actionImageProxy($slug, $x, $y) {
		$image = $this->kufrGameModel->loadImage($slug, $x, $y);
		$image->send();
	}

	public function handleUnhide($slug, $x, $y) {

		$this->kufrGameModel->unhide($slug, $x, $y);
		if ($this->isAjax()) {
			$this->redrawControl("wrapper");
			$this->redrawControl("scoring");
		} else {
			$this->redirect("game", ["slug" => $slug]);
		}

	}

	public function handleUseHint($slug) {

		$this->kufrGameModel->useHint($slug);

		if ($this->isAjax()) {
			$this->redrawControl("scoring");
		} else {
			$this->redirect("game", ["slug" => $slug]);
		}

	}

	public function createComponentEditThing() {

		$form = new Nette\Application\UI\Form;
		$form->addText('name', 'Název:');
		$form->addUpload('image', 'Obrázek:');
		$form->addText('hint', 'Nápověda:');
		$form->addSubmit('submit', 'Uložit');

		$form->onSuccess[] = function ($form, $values) {

			$id = $this->getParameter('id');
			if ($id) {
				$values['id'] = $id;
			}

			$this->kufrGameModel->saveThing($values);
			$this->flashMessage('Uloženo');
			$this->redirect('admin');

		};

		return $form;

	}

	public function createComponentGuessForm() {

		$form = new Nette\Application\UI\Form;

		$form->addText('guess', "Tip:")
			->setRequired("Musíš něco napsat");
		$form->addSubmit('submit', "Tipnout");
		$form->addHidden('slug');

		$form->onSuccess[] = function ($form, $values) {

			$this->result = $this->kufrGameModel->tryGuess($values['slug'], $values['guess']);
			if ($this->isAjax()) {
				$this->redrawControl("scoring");
			} else {
				$this->redirect("game", ["slug" => $values['slug']]);
			}

		};

		return $form;

	}

}
