<?php
/*
 * vim:set softtabstop=4 shiftwidth=4 expandtab:
 *
 * LICENSE: GNU Affero General Public License, version 3 (AGPL-3.0-or-later)
 * Copyright 2001 - 2020 Ampache.org
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 */

declare(strict_types=0);

namespace Ampache\Model;

use Ampache\Module\System\Dba;
use Ampache\Module\Util\Mailer;
use Ampache\Config\AmpConfig;
use Ampache\Module\System\AmpError;
use Ampache\Module\System\Core;
use PDOStatement;

/**
 * This is the class responsible for handling the PrivateMsg object
 * it is related to the user_pvmsg table in the database.
 */
class PrivateMsg extends database_object
{
    protected const DB_TABLENAME = 'user_pvmsg';

    /* Variables from DB */
    /**
     * @var integer $id
     */
    public $id;

    /**
     * @var string $subject
     */
    public $subject;

    /**
     * @var string $message
     */
    public $message;

    /**
     * @var integer $from_user
     */
    public $from_user;

    /**
     * @var integer $to_user
     */
    public $to_user;

    /**
     * @var integer $creation_date
     */
    public $creation_date;

    /**
     * @var boolean $is_read
     */
    public $is_read;

    /**
     * @var string $f_subject
     */
    public $f_subject;

    /**
     * @var string $f_message
     */
    public $f_message;

    /**
     * @var string $link
     */
    public $link;

    /**
     * @var string $f_link
     */
    public $f_link;

    /**
     * @var string $f_from_user_link
     */
    public $f_from_user_link;

    /**
     * @var string $f_to_user_link
     */
    public $f_to_user_link;

    /**
     * @var string $f_creation_date
     */
    public $f_creation_date;

    /**
     * __construct
     * @param integer $pm_id
     */
    public function __construct($pm_id)
    {
        $info = $this->get_info($pm_id, 'user_pvmsg');
        foreach ($info as $key => $value) {
            $this->$key = $value;
        }

        return true;
    }

    public function getId(): int
    {
        return (int) $this->id;
    }

    public function getSenderUserLink(): string
    {
        $from_user = new User($this->from_user);
        $from_user->format();

        return $from_user->f_link;
    }

    public function getRecipientUserLink(): string
    {
        $to_user = new User($this->to_user);
        $to_user->format();

        return $to_user->f_link;
    }

    public function getCreationDateFormatted(): string
    {
        return get_datetime((int) $this->creation_date);
    }

    public function getLinkFormatted(): string
    {
        return sprintf(
            '<a href="%s/pvmsg.php?pvmsg_id=%d">%s</a>',
            AmpConfig::get('web_path'),
            $this->id,
            $this->getSubjectFormatted()
        );
    }

    public function getSubjectFormatted(): string
    {
        return scrub_out($this->subject);
    }

    /**
     * @param array $data
     * @return boolean|string|null
     */
    public static function create(array $data)
    {
        $subject = trim(strip_tags(filter_var($data['subject'], FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES)));
        $message = trim(strip_tags(filter_var($data['message'], FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES)));

        if (empty($subject)) {
            AmpError::add('subject', T_('Subject is required'));
        }

        $to_user = User::get_from_username($data['to_user']);
        if (!$to_user->id) {
            AmpError::add('to_user', T_('Unknown user'));
        }

        if (!AmpError::occurred()) {
            $from_user     = $data['from_user'] ?: Core::get_global('user')->id;
            $creation_date = $data['creation_date'] ?: time();
            $is_read       = $data['is_read'] ?: 0;
            $sql           = "INSERT INTO `user_pvmsg` (`subject`, `message`, `from_user`, `to_user`, `creation_date`, `is_read`) " . "VALUES (?, ?, ?, ?, ?, ?)";
            if (Dba::write($sql, array($subject, $message, $from_user, $to_user->id, $creation_date, $is_read))) {
                $insert_id = Dba::insert_id();

                // Never send email in case of user impersonation
                if (!isset($data['from_user']) && $insert_id) {
                    if (Preference::get_by_user($to_user->id, 'notify_email')) {
                        if (!empty($to_user->email) && Mailer::is_mail_enabled()) {
                            $mailer = new Mailer();
                            $mailer->set_default_sender();
                            $mailer->recipient      = $to_user->email;
                            $mailer->recipient_name = $to_user->fullname;
                            $mailer->subject        = "[" . T_('Private Message') . "] " . $subject;
                            /* HINT: User fullname */
                            $mailer->message = sprintf(T_("You received a new private message from %s."),
                                Core::get_global('user')->fullname);
                            $mailer->message .= "\n\n----------------------\n\n";
                            $mailer->message .= $message;
                            $mailer->message .= "\n\n----------------------\n\n";
                            $mailer->message .= AmpConfig::get('web_path') . "/pvmsg.php?action=show&pvmsg_id=" . $insert_id;
                            $mailer->send();
                        }
                    }
                }

                return $insert_id;
            }
        }

        return false;
    }
}
