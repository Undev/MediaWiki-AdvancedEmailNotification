<?php
/**
 * Internationalisation file for extension AdvancedEmailNotification.
 *
 * @file
 * @ingroup Extensions
 */

$messages = array();

$messages['ru'] = array(
    'AdvancedEmailNotification-newArticle' => 'Изменением является создание страницы.',
	'emailsubject' => 'Изменения затрагивающие ',
    'enotif_body' => 'Здравствуйте, $WATCHINGUSERNAME,<br><br>

$TIMESTAMP участником $PAGEEDITOR_WIKI была изменена страница &laquo;$PAGE&raquo; проекта &laquo;$SITENAME&raquo; с именем.<br><br>

$DIFF<br><br>

Вы можете ознакомиться с $NEWPAGE изменениями, а также со всеми $ALLPAGECHANGES.',
);