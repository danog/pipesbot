#!/usr/bin/env php
<?php

/**
 * Pipes bot.
 *
 * Copyright 2016-2019 Daniil Gentili
 * (https://daniil.it)
 * This file is part of MadelineProto.
 * MadelineProto is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * MadelineProto is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Affero General Public License for more details.
 * You should have received a copy of the GNU General Public License along with MadelineProto.
 * If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2019 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/licenses/AGPL-3.0 AGPLv3
 * @link https://docs.madelineproto.xyz MadelineProto documentation
 */
use danog\MadelineProto\API;
use danog\MadelineProto\EventHandler;
use danog\MadelineProto\Exception;
use danog\MadelineProto\RPCErrorException;

if (!file_exists('madeline.php')) {
    copy('https://phar.madelineproto.xyz/madeline.php', 'madeline.php');
}
include 'madeline.php';

// Login as a user
$u = new API('pipesuser.madeline');
if (!$u->getSelf()) {
    if (!$_GET) {
        $u->echo("Please login as a user!");
    }
    $u->start();
}
if (!$u->isSelfUser()) {
    throw new AssertionError("You must login as a user! Please delete the user.madeline folder to continue.");
}
unset($u);

/**
 * Event handler class.
 */
class pipesbot extends EventHandler
{
    const WELCOME = "This bot can create a pipeline between inline bots.
To use it, simply type an inline query with the following syntax:

```
@pipesbot text | @botname:filter | @botname2 \$
```

Example:
```
@pipesbot Hey I'm writing this using the leet filter of @filtersbot w/ @lolcatzbot | @filtersbot:eleet | @lolcatzbot \$
```

@pipesbot will:
1) Make an inline query with text \"Hey I'm writing this using the leet filter of @filtersbot w/ @lolcatzbot\" to @filtersbot
2) Take the result that has the word \"eleet\" in the title (regexes are supported; omit the selector to select the first result)
3) If it's a text message it will make an inline query to `@lolcatzbot` with the text received from the first bot
4) Fetch all results of the query to @lolcatzbot and return them to you.

Intermediate media results will be ignored.
Note that the query must be terminated by a \$.

Created by @daniilgentili using @MadelineProto (https://docs.madelineproto.xyz).";

    const SWITCH_PM = ['switch_pm' => ['_' => 'inlineBotSwitchPM', 'text' => 'FAQ', 'start_param' => 'lel']];
    const ADMIN = '@danogentili';

    /**
     * User instance of MadelineProto.
     */
    private API $u;

    public function onStart(): void
    {
        $this->u = new API('pipesuser.madeline');
    }

    private function inputify(&$stuff)
    {
        $stuff['_'] = 'input'.ucfirst($stuff['_']);

        return $stuff;
    }
    private function translatetext(&$value): void
    {
        $this->inputify($value);
        if (isset($value['entities'])) {
            foreach ($value['entities'] as &$entity) {
                if ($entity['_'] === 'messageEntityMentionName') {
                    $this->inputify($entity);
                }
            }
        }
        if (isset($value['geo'])) {
            $value['geo_point'] = $this->inputify($value['geo']);
        }
    }
    private function translate(&$value, $key)
    {
        switch ($value['_']) {
            case 'botInlineResult':
                $value['_'] = 'inputBotInlineResult';
                $this->translatetext($value['send_message']);

                return $value;
            case 'botInlineMediaResult':
                if (isset($value['game'])) {
                    throw new Exception('Games are not supported.');
                }
                if (isset($value['photo'])) {
                    $value['_'] = 'inputBotInlineResultPhoto';
                }
                if (isset($value['document'])) {
                    $value['_'] = 'inputBotInlineResultDocument';
                }
                $this->translatetext($value['send_message']);

                return $value;
        }
    }
    public function onUpdateNewChannelMessage($update)
    {
        $this->onUpdateNewMessage($update);
    }
    public function onUpdateNewMessage($update)
    {
        if ($update['message']['out'] ?? false) {
            return;
        }
        try {
            if (strpos($update['message']['message'], '/start') === 0) {
                $this->messages->sendMessage(['peer' => $update, 'message' => self::WELCOME, 'reply_to_msg_id' => $update['message']['id'], 'parse_mode' => 'markdown']);
            }
        } catch (Throwable $e) {
            $this->logger($e);
        }
    }
    public function onUpdateBotInlineQuery($update)
    {
        $this->logger("Got query ".$update['query']);
        try {
            $result = ['query_id' => $update['query_id'], 'results' => [], 'cache_time' => 0];

            if ($update['query'] === '') {
                $this->messages->setInlineBotResults($result + self::SWITCH_PM);
            } else {
                $result['private'] = true;
                $this->messages->setInlineBotResults($this->processNonEmptyQuery($update['query'], $update['user_id'], $result));
            }
        } catch (Throwable $e) {
            try {
                $this->messages->sendMessage(['peer' => self::ADMIN, 'message' => $e->getCode().': '.$e->getMessage().PHP_EOL.$e->getTraceAsString()]);
                //$this->messages->sendMessage(['peer' => $update['user_id'], 'message' => $e->getCode().': '.$e->getMessage().PHP_EOL.$e->getTraceAsString()]);
            } catch (RPCErrorException $e) {
                $this->logger($e);
            } catch (Exception $e) {
                $this->logger($e);
            }

            try {
                $this->messages->setInlineBotResults($result + self::SWITCH_PM);
            } catch (RPCErrorException $e) {
                $this->logger($e);
            } catch (Exception $e) {
                $this->logger($e);
            }
        }
    }

    private function processNonEmptyQuery($query, $user_id, $toset)
    {
        if (preg_match('|(.*)\$\s*$|', $query, $content)) {
            $exploded = array_map('trim', explode('|', $content[1]));
            $query = array_shift($exploded);

            foreach ($exploded as $current => $botSelector) {
                if (strpos($botSelector, ':') === false) {
                    $botSelector .= ':';
                }
                [$bot, $selector] = explode(':', $botSelector);
                if ($bot === '' || $this->u->getInfo($bot)['bot_api_id'] === $this->getSelf()['id']) {
                    return $toset + self::SWITCH_PM;
                }
                $results = $this->u->messages->getInlineBotResults(['bot' => $bot, 'peer' => $user_id, 'query' => $query, 'offset' => '0']);
                $this->logger($results);
                if (isset($results['switch_pm'])) {
                    $toset['switch_pm'] = $results['switch_pm'];
                    return $toset;
                }
                $toset['gallery'] = $results['gallery'];
                $toset['results'] = [];

                if (is_numeric($selector)) {
                    $toset['results'][0] = $results['results'][$selector - 1];
                } elseif ($selector === '') {
                    $toset['results'] = $results['results'];
                } else {
                    foreach ($results['results'] as $result) {
                        if (isset($result['send_message']['message']) && preg_match('|'.$select.'|', $result['send_message']['message'])) {
                            $toset['results'][0] = $result;
                        }
                    }
                }
                if (!isset($toset['results'][0])) {
                    $toset['results'] = $results['results'];
                }
                if (count($exploded) - 1 === $current || !isset($toset['results'][0]['send_message']['message'])) {
                    break;
                }
                $query = $toset['results'][0]['send_message']['message'];
            }
        }
        if (empty($toset['results'])) {
            $toset += self::SWITCH_PM;
        } else {
            array_walk($toset['results'], [$this, 'translate']);
        }
        return $toset;
    }
}

PipesBot::startAndLoopBot('pipesbot.madeline', '<token>');