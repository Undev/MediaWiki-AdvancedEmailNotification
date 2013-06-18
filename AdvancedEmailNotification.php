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
    'version' => 0.1,
);

$wgExtensionMessagesFiles[] = dirname(__FILE__) . '/AdvancedEmailNotification.i18n.php';

class AdvancedEmailNotification
{
    private $dbw;
    private $watchers;
    private $sendMail;

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

    private function getDb()
    {
        if (is_null($this->dbw)) {
            $this->dbw = wfGetDB(DB_MASTER);
        }

        return $this->dbw;
    }


    private function getWatchers()
    {
        return $this->watchers;
    }

    private function setWatchers(array $watchers)
    {
        $this->watchers = $this->getUsers($watchers);
    }

    public function onArticleSave(&$article, &$editor)
    {
        $title = $article->getTitle();
        $this->isCategory = strpos($title->getPrefixedText(), ':') ? true : false;

        $usersNotifiedOnCategoryChanges = $this->getUsersNotifiedOnCategoryChanges($editor, $title);
        $usersNotifiedOnPageChanges = $this->getUsersNotifiedOnPageChanges($editor, $title);

        $usersNotified = array_merge($usersNotifiedOnCategoryChanges, $usersNotifiedOnPageChanges);
        $usersNotified = array_unique($usersNotified);

        $this->setWatchers($usersNotified);

        return true;
    }

    public function onAlternateUserMailer($headers, $to, $from, $subject, $body)
    {
        if (!$this->sendMail) {
            return false;
        }

        return true;
    }


    public function onArticleSaveComplete(&$article, &$editor)
    {
        global $wgSitename;

        $title = $article->getTitle();
        $pageTitle = $title->getText();
        $watchers = $this->getWatchers();

        $newRevision = $article->getRevision();
        if ($newRevision) {
            $oldRevision = $newRevision->getPrevious() ? $newRevision->getPrevious() : $newRevision;
            $newid = $newRevision->getId();
            $oldid = $oldRevision->getId();

            $editorLink = Linker::userLink($editor->getId(), $editor->getName());
            $pageLink = Linker::link($title);
            $newPageDiffLink = Html::element('a', array('href' => $title->getCanonicalUrl("diff={$newid}&oldid={$oldid}")), 'текущими');
            $allPageDiffLink = Html::element('a', array('href' => $title->getCanonicalUrl('action=history')), 'остальными');

            if ($oldRevision == $newRevision) {
                $diff = wfMessage('AdvancedEmailNotification-newArticle')->inContentLanguage()->plain();
            } else {
                $diff = FeedUtils::formatDiffRow($title, $oldRevision->getId(), $newRevision->getId(), $newRevision->getTimestamp(), $newRevision->getComment());
            }

            if (!empty($watchers)) {
                foreach ($watchers as $watchingUser) {
                    if ($watchingUser instanceof User) {
                        $keys = array(
                            '$WATCHINGUSERNAME' => $watchingUser->getName(),
                            '$TIMESTAMP' => date('j F'),
                            '$PAGETITLE' => $pageTitle,
                            '$PAGE' => $pageLink,
                            '$PAGEEDITOR_WIKI' => $editorLink,
                            '$SITENAME' => $wgSitename,
                            '$DIFF' => $diff,
                            '$NEWPAGE' => $newPageDiffLink,
                            '$ALLPAGECHANGES' => $allPageDiffLink,
                        );

                        $this->sendMail = true;
                        $this->composeMail($watchingUser, $keys, $title);
                    }
                }
            }
        }

        return true;
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

    private function getUsers(array $watchers)
    {
        $users = array();
        foreach ($watchers as $userId) {
            $users[] = User::newFromId($userId);
        }
        return $users;
    }

    private function getUsersNotifiedOnCategoryChanges(User $editor, Title $title)
    {
        global $wgEnotifWatchlist, $wgShowUpdatedMarker;

        $categories = $this->getCategories($title);

        $watchers = array();

        if (!empty($categories)) {
            foreach ($categories as $category) {
                if ($wgEnotifWatchlist or $wgShowUpdatedMarker) {
                    $dbw = $this->getDb();
                    $res = $dbw->select(array('watchlist'),
                        array('wl_user'),
                        array(
                            'wl_user != ' . intval($editor->getID()),
//                            'wl_namespace' => $title->getNamespace(),
                            'wl_title' => $category,
                            'wl_notificationtimestamp IS NULL',
                        ), __METHOD__
                    );

                    foreach ($res as $row) {
                        $watchers[] = intval($row->wl_user);
                    }

                    $dbw->freeResult($res);
                    $this->updateTimestamp($title, $watchers);
                }
            }

            $watchers = array_unique($watchers);
        }

        return $watchers;
    }

    private function getUsersNotifiedOnPageChanges(User $editor, Title $title)
    {
        global $wgEnotifWatchlist, $wgShowUpdatedMarker;

        $watchers = array();
        if ($wgEnotifWatchlist or $wgShowUpdatedMarker) {
            $dbw = $this->getDb();
            $res = $dbw->select(array('watchlist'),
                array('wl_user'),
                array(
//                    'wl_user != ' . intval($editor->getID()),
                    'wl_namespace' => $title->getNamespace(),
                    'wl_title' => $title->getDBkey(),
                    'wl_notificationtimestamp IS NULL',
                ), __METHOD__
            );
            foreach ($res as $row) {
                $watchers[] = intval($row->wl_user);
            }

            $dbw->freeResult($res);
            $this->updateTimestamp($title, $watchers);
        }

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

        $dbw = $this->getDb();
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

    private function composeMail(User $watchingUser, array $keys, Title $title)
    {
        if (!$watchingUser->getOption('enotifwatchlistpages') or !$watchingUser->isEmailConfirmed())
            return false;

        $to = new MailAddress($watchingUser);
        $from = $this->getMailFrom();
        $subject = $this->getMailSubject($title);
        $body = $this->getMailBody($keys);

        $status = UserMailer::send($to, $from, $subject, $body, null, 'text/html; charset=UTF-8');

        return true;

    }

    private function getMailBody(array $keys)
    {
        return strtr(wfMessage('enotif_body')->inContentLanguage()->plain(), $keys);
    }

    private function getMailSubject(Title $title)
    {
        return wfMessage('emailsubject')->inContentLanguage()->plain() . $title->getText();
    }

    private function getMailFrom()
    {
        global $wgPasswordSender, $wgPasswordSenderName;
        return new MailAddress($wgPasswordSender,
            isset($wgPasswordSenderName) ? $wgPasswordSenderName : 'WikiAdmin');
    }
}

function wfSetupAdvancedEmailNotification()
{
    global $wgAdvancedEmailNotification;

    $wgAdvancedEmailNotification = new AdvancedEmailNotification;
}
