<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use TelegramBot\Api\BotApi;
use app\models\User;
use DateTime;
use DateTimeZone;
use Exception;

/**
 * Обработка запросов
 */
class SiteController extends Controller
{
    private $host;
    
    public function actionIndex()
    {
        return '';
    }
    
    /**
     * Прием запросов от сервера Телеграм
     */
    public function actionHook($site = 'ru')
    {
        $update = Yii::$app->request->post();
        Yii::info(print_r($update, true), 'hook');
        
        $hosts = [
            'ru' => [
                'regexp' => 'www\.sports\.ru',
                'url' => 'www.sports.ru',
                'id' => 'ru',
            ],
            'ua' => [
                'regexp' => 'ua\.tribuna\.com',
                'url' => 'ua.tribuna.com',
                'id' => 'ua',
            ],
        ];
        $this->host = $hosts[$site];
        
        if (isset($update['message']['text']) && $update['message']['chat']['type'] == 'private') {
            $params = explode(' ', $update['message']['text']);
            $command = array_shift($params);
            $method = 'command'.ucfirst(ltrim($command, '/'));
            if (method_exists($this, $method) && is_callable([$this, $method])) {
                $this->$method($params, $update['message']['chat']);
            } elseif (preg_match('/https:\/\/'.$this->host['regexp'].'\/profile\/\d+[\/]$/', $command)) {
                $this->commandProfile([$command], $update['message']['chat']);
            } else {
                $this->unknownCommand($update['message']['chat']);
            }
        }
    }
    
    /**
     * Начало работы, регистрация пользователя
     */
    public function commandStart($params, $chat)
    {
        $user = User::findOne(['chat_id' => $chat['id'], 'site' => $this->host['id']]);
        if ($user === null) {
            $user = new User([
                'chat_id' => $chat['id'],
                'first_name' => isset($chat['first_name'])? $chat['first_name'] : '',
                'last_name' => isset($chat['last_name'])? $chat['last_name'] : '',
                'username' => isset($chat['username'])? $chat['username'] : '',
                'profile_url' => '',
                'notification' => true,
                'site' => $this->host['id'],
            ]);
            $user->save();
            $message = 'Привет! Отправь мне ссылку на свой профиль и я буду напоминать тебе о важных событиях';
        } elseif (empty($user->profile_url)) {
            $message = 'Ты ещё не отправил мне ссылку на свой профиль';
        } elseif (!$user->notification) {
            $user->notification = true;
            $user->save();
            $message = 'Ты подписался на уведомления. Если захочешь отписаться, набери /stop';
        } else {
            $message = 'Привет! Если тебе нужна помощь, набери /help';
        }
        
        $this->send($chat['id'], $message);
    }
    
    /**
     * Установка ссылки на профиль
     */
    public function commandProfile($params, $chat)
    {
        if (count($params) == 1) {
            if (preg_match('/^https:\/\/'.$this->host['regexp'].'\/profile\/\d+[\/]$/', $params[0])) {
                $user = User::findOne(['chat_id' => $chat['id'], 'site' => $this->host['id']]);
                if ($user === null) {
                    $message = 'Кажется мы ещё не здоровались. Отправь мне /start';
                } else {
                    $checkUser = User::findOne(['profile_url' => $params[0]]);
                    if ($checkUser !== null && $checkUser->id !== $user->id) {
                        $message = 'Пользователь с такой ссылкой уже зарегистрирован.';
                    } else {
                        $user->profile_url = $params[0];
                        $user->save();
                        $user->updateTeams();
                        $message = 'Всё отлично. Молодец! Я запомнил ссылку на твой профиль. ';
                        $message .= 'Чтоб посмотреть список команд, отправь мне /teams';
                    }
                }
            } else {
                $message = 'Ты мне неправильно отправил ссылку. Просто зайди на сайт '.$this->host['url'].' и ';
                $message .= 'скопируй ссылку на свою страницу. ';
                $message .= 'Там должны быть ссылка вида https://'.$this->host['url'].'/profile/12345/';
            }
        } else {
            $message = 'Нужно просто отправить ссылку на свой профиль';
        }
        
        $this->send($chat['id'], $message);
    }
    
    /**
     * Список дедлайнов
     */
    public function commandDeadlines($params, $chat)
    {
        $user = User::findOne(['chat_id' => $chat['id'], 'site' => $this->host['id']]);
        $formatter = Yii::$app->formatter;
        if ($user === null) {
            $message = 'Кажется мы ещё не здоровались. Отправь мне /start';
        } else {
            if (count($user->teams) > 0) {
                $message = 'Дедлайны:';
                $deadlines = [];
                foreach ($user->teams as $team) {
                    $deadlines[$team->tournament->name] = $team->tournament->deadline;
                }
                asort($deadlines);
                foreach ($deadlines as $name => $deadline) {
                    $date = new DateTime($deadline, new DateTimeZone(Yii::$app->timeZone));
                    $message .= "\n- ".$name.': '.$this->formatDate($date);
                }
            } else {
                $message = 'Ты не создал ещё ни одной команды или не отправил мне ссылку на свой профиль';
            }
        }

        $this->send($chat['id'], $message);
    }
    
    /**
     * Форматирует вывод даты
     */
    private function formatDate($date)
    {
        $result = $date->format('j').' ';
        
        $months = [
            1 => 'января',
            'февраля',
            'март',
            'апреля',
            'мая',
            'июня',
            'июля',
            'августа',
            'сентября',
            'октября',
            'ноября',
            'декабря',
        ];
        $result .= $months[$date->format('n')].' (';
        
        $days = [
            1 => 'пн',
            'вт',
            'ср',
            'чт',
            'пт',
            'сб',
            'вс',
        ];
        $result .= $days[$date->format('N')].') ';
        
        $result .= $date->format('H:i');
        
        return $result;
    }
    
    /**
     * Список команд пользователя
     */
    public function commandTeams($params, $chat)
    {
        $user = User::findOne(['chat_id' => $chat['id'], 'site' => $this->host['id']]);
        if ($user === null) {
            $message = 'Кажется мы ещё не здоровались. Отправь мне /start';
        } else {
            if (count($user->teams) > 0) {
                $message = 'Твои команды:';
                foreach ($user->teams as $team) {
                    $message .= "\n- ".$team->name.' ('.$team->tournament->name.')';
                }
            } else {
                $message = 'Ты не создал ещё ни одной команды или не отправил мне ссылку на свой профиль';
            }
        }

        $this->send($chat['id'], $message);
    }
    
    /**
     * Отписка от уведомлений
     */
    public function commandStop($params, $chat)
    {
        $user = User::findOne(['chat_id' => $chat['id'], 'site' => $this->host['id']]);
        if ($user === null) {
            $message = 'Кажется мы ещё не здоровались. Отправь мне /start';
        } elseif ($user->notification) {
            $user->notification = false;
            $user->save();
            $message = 'Ты отписался от напоминаний. Если передумал, набери /start';
        } else {
            $message = 'Ты уже отписан от напоминаний. Если хочет снова подписаться, набери /start';
        }
        
        $this->send($chat['id'], $message);
    }
    
    /**
     * Помощь, список доступных команд
     */
    public function commandHelp($params, $chat)
    {
        $message = 'Вот команды, которые я понимаю:'."\n";
        $message .= '/profile url - сообщить ссылку на свой профиль'."\n";
        $message .= '/deadlines - дедлайны турниров'."\n";
        $message .= '/teams - список твоих фентези-команд'."\n";
        $message .= '/stop - отписаться от уведомлений';
        
        $this->send($chat['id'], $message);
    }
    
    /**
     * Неизвестная команда, ошибка
     */
    public function unknownCommand($chat)
    {
        $this->send($chat['id'], 'Я не понимаю тебя. Чтоб посмотреть список известных мне команд просто набери /help');
    }
    
    /**
     * Отправляет сообщение
     */
    public function send($chatId, $message)
    {
        $bot = new BotApi(Yii::$app->params['token'][$this->host['id']]);
        try {
            $bot->sendMessage($chatId, $message);
        } catch (Exception $e) {
            Yii::info(print_r($e, true), 'send');
        }
    }
}
