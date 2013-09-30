<?php
/**
 * Internationalisation file for extension AdvancedEmailNotification.
 *
 * @file
 * @ingroup Extensions
 */

$messages = array();

$messages['ru'] = array(
    'subscribeCondition-page' => 'Вы получили это письмо, потому что подписаны на изменения данной статьи',
    'subscribeCondition-category' => 'Вы получили это письмо, потому что подписаны на следующие категории: ',
    'watchlist-edit-link' => 'здесь',

    'emailsubject' => '{{pageTitle}} изменена {{editorName}} [{{siteName}}]',
    'enotif_body' => <<<HTML
    <div class="container">
        <table class="table">
            <tbody>
                <tr>
                    <td style="width: 150px;">Автор</td>
                    <td>{{editorLink}}</td>
                </tr>
                <tr>
                    <td>Статья</td>
                    <td>{{pageLink}}</td>
                </tr>
                <tr>
                    <td>Новая ревизия</td>
                    <td>{{timestamp}}</td>
                </tr>
                <tr>
                    <td>Категории статьи</td>
                    <td>{{pageCategories}}</td>
                </tr>
            </tbody>
        </table>
        {{diffTable}}

        <pre>
            {{subscribeCondition}}
            Вы можете отредактировать свой список наблюдения {{editWatchlistLink}}.
        </pre>
    </div>
HTML

);