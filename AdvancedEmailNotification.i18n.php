<?php
/**
 * Internationalisation file for extension AdvancedEmailNotification.
 *
 * @file
 * @ingroup Extensions
 */

$messages = array();

$messages['ru'] = array(
    'emailsubject' => '#pageTitle изменена #editorName [#siteName]',
    'enotif_body' => <<<HTML
    <div class="container">
        <table class="table">
            <tbody>
                <tr>
                    <td style="width: 150px;">Автор</td>
                    <td>#editorLink</td>
                </tr>
                <tr>
                    <td>Статья</td>
                    <td>#articleLink</td>
                </tr>
                <tr>
                    <td>Новая ревизия</td>
                    <td>#timestamp</td>
                </tr>
                <tr>
                    <td>Категории статьи</td>
                    <td>#listOfCategories</td>
                </tr>
            </tbody>
        </table>
        #diffTable

        <pre>
            Вы получили это письмо, потому что подписаны на изменения данной #oldRevisionLink.
            Редактировать свой Список наблюдения можно #watchListLink.
        </pre>
    </div>
HTML
);