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
	'emailsubject' => '$PAGETITLE изменена $PAGEEDITOR [$SITENAME]',
    'enotif_body' => '
Автор: $PAGEEDITOR_WIKI.<br>
Статья: $PAGE.<br>
Новая ревизия: $TIMESTAMP. Посмотреть $NEWPAGE.<br>
Категории статьи: $CATEGORIES.<br><br>

$DIFF<br><br>

Вы получили это письмо, потому что подписаны на изменения $PAGEOLD.<br>
Редактировать подписки можно $WATCHLISTEDIT.
',
);