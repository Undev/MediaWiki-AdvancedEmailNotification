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
    'author' => '[http://www.facebook.com/denisovdenis Denisov Denis]',
    'url' => 'https://github.com/Undev/wiki-AdvancedEmailNotification',
    'description' => 'Wiki Advanced email notification. Inline diffs, category subscribe.',
    'version' => 0.2,
);
$wgExtensionMessagesFiles[] = dirname(__FILE__) . '/AdvancedEmailNotification.i18n.php';

class AdvancedEmailNotification
{
    private $isCategory;
    private $watchers;
    private $editor;

    private $isOurUserMailer;

    private $newRevision;
    private $oldRevision;

    private $title;

    function __construct()
    {
        global $wgHooks;

        $wgHooks['ArticleSave'][] = $this;
        $wgHooks['AlternateUserMailer'][] = $this;
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


    public function onArticleSave(&$article, &$editor)
    {
        if (!$this->init()) {
            return true;
        }

        $categoryWatchers = $this->getCategoryWatchers();
        $pageWatchers = $this->getPageWatchers();

        $watchers = array_merge($categoryWatchers, $pageWatchers);
        $watchers = array_unique($watchers);

        $this->isCategory = empty($categoryWatchers) ? false : true;

        $users = array();
        foreach ($watchers as $userId) {
            $users[] = User::newFromId($userId);
        }

        $this->watchers = $users;

        return true;
    }

    public function onAlternateUserMailer($headers, $to, $from, $subject, $body)
    {
        if ($this->isOurUserMailer) {
            return true;
        }

        return false;
    }

    public function onArticleSaveComplete(&$article, &$editor)
    {
        if (!$this->init()) {
            return true;
        }

        if (empty($this->watchers)) {
            return false;
        }


        $this->editor = $editor;
        $this->composeMail();

        return true;
    }

    private function checkWatchers()
    {
        global $wgEnotifWatchlist, $wgShowUpdatedMarker;

        if (!$this->editor or (!$wgEnotifWatchlist and !$wgShowUpdatedMarker)) {
            return false;
        }

        return true;
    }

    private function getCategoryWatchers()
    {
        $this->checkWatchers();

        $categories = $this->getCategories($this->title);
        $watchers = array();
        foreach ($categories as $category) {
            $dbw = wfGetDB(DB_MASTER);
            $res = $dbw->select(array('watchlist'),
                array('wl_user'),
                array(
//                    'wl_user != ' . intval($this->editor->getID()),
                    'wl_title' => $category,
//                    'wl_notificationtimestamp IS NULL',
                ), __METHOD__
            );

            foreach ($res as $row) {
                $watchers[] = intval($row->wl_user);
            }

            $dbw->freeResult($res);
            $this->updateTimestamp($this->title, $watchers);
        }

        $watchers = array_unique($watchers);

        return $watchers;
    }

    private function getPageWatchers()
    {
        $this->checkWatchers();

        $dbw = wfGetDB(DB_MASTER);
        $res = $dbw->select(array('watchlist'),
            array('wl_user'),
            array(
                'wl_namespace' => $this->title->getNamespace(),
                'wl_title' => $this->title->getDBkey(),
//                'wl_notificationtimestamp IS NULL',
            ), __METHOD__
        );

        $watchers = array();
        foreach ($res as $row) {
            $watchers[] = intval($row->wl_user);
        }

        $dbw->freeResult($res);
        $this->updateTimestamp($this->title, $watchers);

        $watchers = array_unique($watchers);

        return $watchers;
    }

    private function updateTimestamp(Title $title, array $watchers)
    {
        // @todo Обновлять таймстамп
        return;
        if (!$watchers) {
            return false;
        }

        $dbw = wfGetDB(DB_MASTER);
        $timestamp = time();
        $fName = __METHOD__;
        $dbw->onTransactionIdle(
            function () use ($watchers, $title, $dbw, $timestamp, $fName) {
                $dbw->begin($fName);
                $dbw->update('watchlist',
                    array( /* SET */
                        'wl_notificationtimestamp' => $dbw->timestamp($timestamp)
                    ), array( /* WHERE */
                        'wl_user' => $watchers,
                        'wl_namespace' => $title->getNamespace(),
                        'wl_title' => $title->getDBkey(),
                    ), $fName
                );
                $dbw->commit($fName);
            }
        );

        return true;
    }

    private function composeMail()
    {
        global $wgServer, $wgLang, $wgSitename, $wgPasswordSender;

        $protocol = substr($wgServer, 0, strpos($wgServer, ':'));

        $userWikiPage = Title::makeTitle(NS_USER, $this->editor->getName());
        $editorLink = Linker::link($userWikiPage, $this->editor->getName(), array('class' => 'mw-userlink'), array(), $protocol);

        $articleLink = Html::element('a', array('href' => $this->title->getFullUrl()), $this->title->getText());

        // @todo Здесь должна быть ссылка на категорию, либо на статью. Определить категорию.
        $oldRevisionLink = ($this->isCategory ? 'категории' : 'статьи');
//      $oldRevisionLink .= Linker::link($this->oldRevision->getTitle(),
//      $this->oldRevision->getTitle()->getText(), array(), array(), $protocol);

        $watchListEditLink = Html::element('a', array('href' => $wgServer . '/' . 'Special:Править_список_наблюдения'), 'здесь');

        $timestamp = $wgLang->timeanddate($this->newRevision->getTimestamp());
        $categories = $this->getCategories($this->title);
        $categories = implode(', ', $categories);

        $diff = $this->getDiff();

        foreach ($this->watchers as $watchingUser) {
            if ($watchingUser instanceof User) {
                $keys = array(
                    // For subject
                    '#siteName' => $wgSitename,
                    '#editorName' => $this->editor->getName(),
                    '#pageTitle' => $this->title->getText(),

                    // For body
                    '#editorLink' => $editorLink,
                    '#articleLink' => $articleLink,
                    '#timestamp' => $timestamp,
                    '#listOfCategories' => $categories,
                    '#diffTable' => $diff,
                    '#oldRevisionLink' => $oldRevisionLink,
                    '#watchListLink' => $watchListEditLink,
                );

                $this->isOurUserMailer = true;

                if (!$watchingUser->getOption('enotifwatchlistpages') or !$watchingUser->isEmailConfirmed()) {
                    continue;
                }

                $to = new MailAddress($watchingUser);
                $from = new MailAddress($wgPasswordSender, $wgSitename);
                $subject = strtr(wfMessage('emailsubject')->inContentLanguage()->plain(), $keys);

                $css = file_get_contents('css/mail.min.css', FILE_USE_INCLUDE_PATH);
                $body = strtr(wfMessage('enotif_body')->inContentLanguage()->plain(), $keys);
                $body = "<html><head><style>$css</style></head><body>$body</body></html>";

                $status = UserMailer::send($to, $from, $subject, $body, null, 'text/html; charset=UTF-8');
            }
        }

        return true;
    }

    private function getDiff()
    {
        global $wgServer;
        // @ todo get 'wl_notificationtimestamp IS NULL',

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
