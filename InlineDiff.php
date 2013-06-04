<?php
/**
 * Author: Denisov Denis
 * Email: denisovdenis@me.com
 * Date: 03.06.13
 * Time: 13:58
 */

$wgExtensionFunctions[] = 'wfSetupInlineDiff';
$wgExtensionCredits['other'][] = array(
    'path' => __FILE__,
    'name' => 'InlineDiff',
    'author' => '[http://www.facebook.com/denisovdenis Denisov Denis]',
    'url' => 'https://github.com/Undev/wiki-inline-diff',
    'description' => 'Wiki notifications contain inline diffs.',
    'version' => 0.1,
);

$wgExtensionMessagesFiles[] = dirname(__FILE__) . '/InlineDiff.i18n.php';

class InlineDiff
{
    function __construct()
    {
        global $wgHooks;
        $wgHooks['ArticleSave'][] = $this;
    }

    function __toString()
    {
        return __CLASS__;
    }

    public function onArticleSave(&$article, &$editor)
    {
        global $wgSitename;

        $title = $article->getTitle();
        $page = $title->getText();

        $newRevision = $article->getRevision();
        $oldRevision = $newRevision->getPrevious();
        $newid = $newRevision->getId();
        $oldid = $oldRevision->getId();

        $editorLink = Linker::userLink($editor->getId(), $editor->getName());
        $pageLink = Linker::link($title);
        $newPageDiffLink = Html::element('a', array('href' => $title->getCanonicalUrl('diff=' . $newid)), 'текущими');
        $allPageDiffLink = Html::element('a', array('href' => $title->getCanonicalUrl('action=history')), 'остальными');

        $diff = FeedUtils::formatDiffRow($title, $oldRevision->getId(), $newRevision->getId(), $newRevision->getTimestamp(), $newRevision->getComment());

        $watchers = $this->getUsersNotifiedOnChanges($editor, $title);

        if (!empty($watchers)) {
            foreach ($watchers as $watchingUser) {
                if ($watchingUser instanceof User) {
                    $keys = array(
                        '$WATCHINGUSERNAME' => $watchingUser->getName(),
                        '$TIMESTAMP' => date('j F'),
                        '$PAGETITLE' => $page,
                        '$PAGE' => $pageLink,
                        '$PAGEEDITOR_WIKI' => $editorLink,
                        '$SITENAME' => $wgSitename,
                        '$DIFF' => $diff,
                        '$NEWPAGE' => $newPageDiffLink,
                        '$ALLPAGECHANGES' => $allPageDiffLink,
                    );

                    $this->composeMail($watchingUser, $keys, $title);
                }
            }
        }

        return true;
    }

    private function getUsersNotifiedOnChanges(User $editor, Title $title)
    {
        global $wgEnotifWatchlist, $wgShowUpdatedMarker;

        $watchers = array();
        if ($wgEnotifWatchlist or $wgShowUpdatedMarker) {
            $dbw = wfGetDB(DB_MASTER);
            $res = $dbw->select(array('watchlist'),
                array('wl_user'),
                array(
                    'wl_user != ' . intval($editor->getID()),
                    'wl_namespace' => $title->getNamespace(),
                    'wl_title' => $title->getDBkey(),
                    'wl_notificationtimestamp IS NULL',
                ), __METHOD__
            );
            foreach ($res as $row) {
                $watchers[] = intval($row->wl_user);
            }
            if ($watchers) {
                // Update wl_notificationtimestamp for all watching users except the editor
                $timestamp = time();
                $fname = __METHOD__;
                $dbw->onTransactionIdle(
                    function () use ($dbw, $timestamp, $watchers, $title, $fname) {
                        $dbw->begin($fname);
                        $dbw->update('watchlist',
                            array( /* SET */
                                'wl_notificationtimestamp' => $dbw->timestamp($timestamp)
                            ), array( /* WHERE */
                                'wl_user' => $watchers,
                                'wl_namespace' => $title->getNamespace(),
                                'wl_title' => $title->getDBkey(),
                            ), $fname
                        );
                        $dbw->commit($fname);
                    }
                );

                $users = array();
                foreach ($watchers as $userId) {
                    $users[] = User::newFromId($userId);
                }
                $watchers = $users;
            }
        }

        return $watchers;
    }

    private function composeMail(User $watchingUser, array $keys, Title $title)
    {
        if (!$watchingUser->getOption('enotifwatchlistpages') or !$watchingUser->isEmailConfirmed())
            return false;

        $to = new MailAddress($watchingUser);
        $from = $this->getMailFrom();
        $subject = $this->getMailSubject($title);
        $body = $this->getMailBody($keys);

        UserMailer::send($to, $from, $subject, $body, null, 'text/html; charset=UTF-8');

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

function wfSetupInlineDiff()
{
    global $wgInlineDiff;

    $wgInlineDiff = new InlineDiff;
}
