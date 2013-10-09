<?php
/**
 * Author: Denisov Denis
 * Email: denisovdenis@me.com
 * Date: 03.06.13
 * Time: 13:58
 */

$wgExtensionFunctions[] = 'wfSetupAdvancedEmailNotification';
$wgExtensionCredits['other'][] = array(
	'path' => __FILE__,
	'name' => 'AdvancedEmailNotification',
	'author' => '[http://www.denisovdenis.com Denisov Denis]',
	'url' => 'https://github.com/Undev/MediaWiki-AdvancedEmailNotification',
	'description' => 'Adds the ability to watch for categories and nested pages. Replaces the standard message template to a more comfortable with inline diffs.',
	'version' => 1.0,
);
$wgExtensionMessagesFiles[] = dirname(__FILE__) . '/AdvancedEmailNotification.i18n.php';

class AdvancedEmailNotification
{
	/**
	 * Used to prevent standard mail notifications
	 * @var boolean
	 */
	private $isOurUserMailer = false;

	/**
	 * Contains all page categories
	 * @var array
	 */
	private $pageCategories = array();

	/**
	 * All watchers who followed by category for this page
	 * @var array multidimensional
	 */
	private $categoryWatchers = array();

	/**
	 * All watchers who immediately followed for this page
	 * @var array
	 */
	private $pageWatchers = array();

	/**
	 *
	 * @var
	 */
	private $diff;

	private $editor;

	/**
	 * @var Revision
	 */
	private $newRevision;

	/**
	 * @var Revision
	 */
	private $oldRevision;

	/**
	 * @var Title
	 */
	private $title;

	const AEN_TABLE = 'watchlist';
	const AEN_TABLE_EXTENDED = 'watchlist_subpages';
	const AEN_VIEW = 'watchlist_extended';

	function __construct()
	{
		global $wgHooks;

		$wgHooks['AlternateUserMailer'][] = $this;
		$wgHooks['SpecialWatchlistQuery'][] = $this;
		$wgHooks['ArticleSave'][] = $this;
		$wgHooks['ArticleSaveComplete'][] = $this;
	}

	function __toString()
	{
		return __CLASS__;
	}

	public function init()
	{
		try {
			$this->newRevision = RequestContext::getMain()->getWikiPage()->getRevision();
		} catch (Exception $e) {
			return false;
		}

		if (is_null($this->newRevision)) {
			return false;
		}

		$this->oldRevision = $this->newRevision->getPrevious();
		$this->title = $this->newRevision->getTitle();
		$this->editor = User::newFromId($this->newRevision->getUser());

		return true;
	}

	public function onAlternateUserMailer($headers, $to, $from, $subject, $body)
	{
		if ($this->isOurUserMailer) {
			return true;
		}

		return false;
	}

	/**
	 * Extend "Special:Watchlist" by adding changed pages from category.
	 *
	 * Replace standard watchlist table by mysql-view to provide observing
	 * changing pages in categories.
	 *
	 * @param $conds
	 * @param $tables
	 * @param $join_conds
	 * @param $fields
	 * @return bool
	 */
	public function onSpecialWatchlistQuery(&$conds, &$tables, &$join_conds, &$fields)
	{
		// Search in $tables array for watchlist position
		$position = array_search(self::AEN_TABLE, $tables);

		// Replace by View
		$tables[$position] = self::AEN_VIEW;

		// Don't forget to change alias in $join_conds array
		$join_conds[self::AEN_VIEW] = $join_conds[self::AEN_TABLE];
		// Remove old alias
		unset($join_conds[self::AEN_TABLE]);

		return true;
	}

	public function onArticleSave(&$article, &$editor)
	{
		if (!$this->init()) {
			return true;
		}

		// Getting all page categories
		$this->pageCategories = $this->getCategories($this->title);

		// Initialize Database
		$dbw = wfGetDB(DB_MASTER);

		// Search for all users who subscribed this page by received categories
		foreach ($this->pageCategories as $pageCategory) {
			$res = $dbw->select(array(self::AEN_TABLE), array('wl_user'),
				array(
					'wl_title' => $pageCategory,
					'wl_namespace' => NS_CATEGORY,
				), __METHOD__
			);

			// Collect user id and category name which he followed
			foreach ($res as $row) {
				$this->categoryWatchers[intval($row->wl_user)][] = $pageCategory;
			}

			$dbw->freeResult($res);
		}

		// Search for all users who subscribed this page by direct subscription.
		$res = $dbw->select(array(self::AEN_TABLE), array('wl_user'),
			array(
				'wl_namespace' => $this->title->getNamespace(),
				'wl_title' => $this->title->getDBkey(),
				'wl_notificationtimestamp IS NULL',
			), __METHOD__
		);

		foreach ($res as $row) {
			$this->pageWatchers[] = intval($row->wl_user);
		}

		$dbw->freeResult($res);

		return true;
	}

	public function onArticleSaveComplete(&$article, &$editor)
	{
		if (!$this->init()) {
			return true;
		}

		$this->diff = $this->getDiff();

		if (!empty($this->pageWatchers)) {
			foreach ($this->pageWatchers as $userId) {
				$user = User::newFromId($userId);
				if ($this->isUserNotified($user)) {
					$this->notifyByMail($user);
				}
			}

			$this->updateTimestamp($this->pageWatchers);
		}

		if (!empty($this->categoryWatchers)) {
			foreach ($this->categoryWatchers as $userId => $watchedCategories) {
				$user = User::newFromId($userId);
				if ($this->isUserNotified($user)) {
					$this->notifyByMail($user, (string)$watchedCategories);
				}
				$this->notifyByWatchlist($user);
			}
		}

		return true;
	}


	/**
	 * @param User $user
	 * @param $watchedType string
	 */
	private function notifyByMail(User $user, $watchedType = null)
	{
		global $wgSitename,
		       $wgPasswordSender;

		// Prevent standard mail notification
		$this->isOurUserMailer = true;

		// Create link for editor page
		$editorPageTitle = Title::makeTitle(NS_USER, $this->editor->getName());
		$editorLink = Linker::link($editorPageTitle, null, array(), array(), array('http'));

		// Create link for edit user watchlist
		$editWatchlistTitle = Title::makeTitle(NS_SPECIAL, 'Watchlist/Edit');
		$editWatchlistLink = Linker::link($editWatchlistTitle, wfMessage('watchlist-edit-link')->inContentLanguage()->plain(), array(), array(), array('http'));

		// Create link to this page
		$pageLink = Linker::link($this->title, null, array(), array(), array('http'));

		foreach ($this->pageCategories as $category) {
			$categoryTitle = Title::makeTitle(NS_CATEGORY, $category);
			$pageCategories[] = Linker::link($categoryTitle, $category, array(), array(), array('http'));
		}

		if (!empty($pageCategories)) {
			$pageCategories = implode(', ', $pageCategories);
		}

		if (is_null($watchedType)) {
			$subscribeCondition = wfMessage('subscribeCondition-page')->inContentLanguage()->plain();
		} else {
			foreach ($this->categoryWatchers[$user->getId()] as $category) {
				$categoryTitle = Title::makeTitle(NS_CATEGORY, $category);
				$categoryWatch[] = Linker::link($categoryTitle, $category, array(), array(), array('http'));
			}
			$subscribeCondition = wfMessage('subscribeCondition-category')->inContentLanguage()->plain() . implode(', ', $categoryWatch) . '.';
		}

		$keys = array(
			// For subject
			'{{siteName}}' => $wgSitename,
			'{{editorName}}' => $this->editor->getName(),
			'{{pageTitle}}' => $this->title->getText(),

			// For body
			'{{editorLink}}' => $editorLink,
			'{{pageLink}}' => $pageLink,
			'{{timestamp}}' => date('d-m-Y H:i:s', time()),
			'{{pageCategories}}' => $pageCategories,
			'{{diffTable}}' => $this->getDiff(),
			'{{subscribeCondition}}' => $subscribeCondition,
			'{{editWatchlistLink}}' => $editWatchlistLink,
		);

		$to = new MailAddress($user);
		$from = new MailAddress($wgPasswordSender, $wgSitename);
		$subject = strtr(wfMessage('emailsubject')->inContentLanguage()->plain(), $keys);

		$css = file_get_contents('css/mail.min.css', FILE_USE_INCLUDE_PATH);
		$body = strtr(wfMessage('enotif_body')->inContentLanguage()->plain(), $keys);
		$body = "<html><head><style>$css</style></head><body>$body</body></html>";

		$status = UserMailer::send($to, $from, $subject, $body, null, 'text/html; charset=UTF-8');

		if (!empty($status->errors)) {
			return false;
		}

		return true;
	}

	private function notifyByWatchlist(User $user)
	{
		$dbw = wfGetDB(DB_MASTER);

		foreach ($this->categoryWatchers[$user->getId()] as $category) {
			$res = $dbw->select(array(self::AEN_TABLE_EXTENDED),
				array(
					'wls_user',
					'wls_category',
					'wls_title',
				),
				array(
					'wls_user' => $user->getId(),
					'wls_category' => $category,
					'wls_title' => $this->title->getDBkey(),
				), __METHOD__
			);

			if ($res->numRows()) {
				$dbw->freeResult($res);
				continue;
			}

			$dbw->freeResult($res);

			$res = $dbw->insert('watchlist_subpages',
				array(
					'wls_user' => $user->getId(),
					'wls_namespace' => NS_MAIN,
					'wls_category' => $category,
					'wls_title' => $this->title->getDBkey(),
				), __METHOD__
			);

			if (!$res) {
				return false;
			}
		}

		return true;
	}

	private function updateTimestamp(array $watchers)
	{
		if (is_null($this->title)) {
			return false;
		}

		$dbw = wfGetDB(DB_MASTER);
		$fName = __METHOD__;
		$table = self::AEN_TABLE;
		$title = $this->title;

		foreach ($watchers as $watcher) {
			$dbw->onTransactionIdle(
				function () use ($table, $watcher, $title, $dbw, $fName) {
					$dbw->begin($fName);
					$dbw->update($table,
						array('wl_notificationtimestamp' => $dbw->timestamp()),
						array(
							'wl_user' => $watcher,
							'wl_namespace' => $title->getNamespace(),
							'wl_title' => $title->getDBkey(),
						), $fName
					);
					$dbw->commit($fName);
				}
			);
		}

		return true;
	}


	private function getDiff()
	{
		global $wgServer;

		if (!is_null($this->diff)) {
			return $this->diff;
		}

		if (!$this->oldRevision or !$this->newRevision) {
			return false;
		}

		$differenceEngine = new DifferenceEngine(null, $this->oldRevision->getId(), $this->newRevision->getId());
		$differenceEngine->showDiffPage(true);

		$html = RequestContext::getMain()->getOutput()->getHTML();
		$pattern = "/(?<=href=(\"|'))[^\"']+(?=(\"|'))/";
		$diff = preg_replace($pattern, "$wgServer$0", $html);

		return $diff;
	}

	private function getCategories(Title $title)
	{
		$categoriesTree = $this->array_values_recursive($title->getParentCategoryTree());
		$categoriesTree = array_unique($categoriesTree);
		$categories = array();
		foreach ($categoriesTree as $category) {
			if (strpos($category, ':')) {
				$category = explode(':', $category);
				$categories[] = $category[1];
			}
		}

		return $categories;
	}

	/**
	 * User has defined options in preferences which describe if user notified or not.
	 * Look for $this->editor to check if need to send to user a copy of email to other users.
	 *
	 * @param User $user
	 * @return bool
	 */
	private function isUserNotified(User $user)
	{
		global $wgEnotifWatchlist, // Email notifications can be sent for the first change on watched pages (user preference is shown and user needs to opt-in)
		       $wgShowUpdatedMarker; // Show "Updated (since my last visit)" marker in RC view, watchlist and history


		if (!$user->isEmailConfirmed()) {
			return false;
		}

		if (!$wgEnotifWatchlist and !$wgShowUpdatedMarker) {
			return false;
		}

		// Supporting feature "Email me when a page or file on my watchlist is changed"
		if (!$user->getOption('enotifwatchlistpages')) {
			return false;
		}

		// Supporting feature "Send me copies of emails I send to other users"
		if (!is_null($this->editor) and $user->getId() == $this->editor->getId()) {
			if (!$user->getOption('ccmeonemails')) {
				return false;
			}
		}

		return true;
	}


	/**
	 * Recursive function returns all values from tree of categories.
	 *
	 * @param $array
	 * @return array
	 */
	private function array_values_recursive($array)
	{
		$arrayKeys = array();

		foreach ($array as $key => $value) {
			$arrayKeys[] = $key;
			if (!empty($value)) {
				$arrayKeys = array_merge($arrayKeys, $this->array_values_recursive($value));
			}
		}

		return $arrayKeys;
	}
}

function wfSetupAdvancedEmailNotification()
{
	global $wgAdvancedEmailNotification;

	$wgAdvancedEmailNotification = new AdvancedEmailNotification;
}