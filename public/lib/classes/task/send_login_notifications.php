<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace core\task;

/**
 * Adhoc task that send login notifications.
 *
 * @package    core
 * @copyright  2021 Moodle Pty Ltd.
 * @author     Juan Leyva <juan@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_login_notifications extends adhoc_task {

    use \core\task\logging_trait;

    /**
     * Run the adhoc task and preform the backup.
     */
    public function execute() {
        global $CFG, $DB, $SITE, $USER, $PAGE;

        $customdata = $this->get_custom_data();

        // First check the mobile app special case, to detect if the user is not using a new device after login from a different IP.
        if (!empty($customdata->ismoodleapp)) {
            $where = 'userid = ? AND timecreated >= ?';
            if (!$DB->count_records_select('user_devices', $where, [$USER->id, $customdata->logintime])) {
                // Do nothing, seems to be the same person doing login from a new IP using a known device.
                return;
            }
        }

        $this->log_start("Sending login notification to {$USER->username}");
        $sitename = format_string($SITE->fullname);
        $device = self::get_device_description($customdata->useragent ?? '', !empty($customdata->ismoodleapp));

        $changepasswordlink = (new \moodle_url('/user/preferences.php', ['userid' => $USER->id]))->out(false);
        // Find a better final URL for changing password.
        $userauth = \core\di::get(\core\authentication::class)->get_plugin($USER->auth);
        if ($userauth->can_change_password()) {
            if ($changepwurl = $userauth->change_password_url()) {
                $changepasswordlink = $changepwurl;
            } else {
                $changepasswordlink = (new \moodle_url('/login/change_password.php'))->out(false);
            }
        }

        $usercontext = \context_user::instance($USER->id);
        $sessionsurl = null;
        $cansessions = \core_component::get_component_directory('report_usersessions')
            && has_capability('report/usersessions:manageownsessions', $usercontext);
        if ($cansessions) {
            $sessionsurl = (new \moodle_url('/report/usersessions/user.php'))->out(false);
        }
        $preferencesurl = new \moodle_url('/message/notificationpreferences.php', ['userid' => $USER->id]);

        if ($device !== null) {
            $subject = get_string('newloginnotificationsubject', 'moodle', ['sitename' => $sitename, 'device' => $device]);
        } else {
            $subject = get_string('newloginnotificationsubjectnodevice', 'moodle', $sitename);
        }

        $info = [
            'firstname' => s($USER->firstname),
            'sitename' => $sitename,
            // Leave the browser out when it is not recognised, so it does not read as an unknown intruder.
            'browserline' => $device === null ? '' :
                \html_writer::tag('li', get_string('newloginnotificationbrowser', 'moodle', $device)) . "\n",
            'logintime' => userdate($customdata->logintime),
            'timezone' => \core_date::get_localised_timezone(\core_date::get_user_timezone($USER)),
            'place' => self::get_place($customdata->loginip),
            'notyou' => $sessionsurl ?
                get_string('newloginnotificationnotyou', 'moodle', $changepasswordlink) :
                get_string('newloginnotificationnotyounosessions', 'moodle', $changepasswordlink),
            'sessionslink' => $sessionsurl ? get_string('newloginnotificationsessions', 'moodle', $sessionsurl) : '',
            'optout' => self::can_turn_off_emails() ?
                get_string('newloginnotificationoptout', 'moodle', $preferencesurl->out(false)) :
                '',
        ];

        $eventdata = new \core\message\message();
        $eventdata->courseid          = SITEID;
        $eventdata->component         = 'moodle';
        $eventdata->name              = 'newlogin';
        $eventdata->userfrom          = \core_user::get_noreply_user();
        $eventdata->userto            = $USER;
        $eventdata->notification      = 1;
        $eventdata->subject           = $subject;
        $eventdata->fullmessageformat = FORMAT_HTML;
        $eventdata->fullmessagehtml   = get_string('newloginnotificationbody', 'moodle', $info);
        $eventdata->fullmessage       = html_to_text($eventdata->fullmessagehtml);
        $eventdata->smallmessage      = $sessionsurl ? get_string('newloginnotificationsmall', 'moodle', $subject) : $subject;

        $userpicture = new \user_picture($USER);
        $userpicture->size = 1; // Use f1 size.
        $userpicture->includetoken = $USER->id; // Generate an out-of-session token for the user receiving the message.
        $eventdata->customdata = ['notificationiconurl' => $userpicture->get_url($PAGE)->out(false)];

        if (message_send($eventdata)) {
            $this->log_finish("Notification successfully sent");
        } else {
            $this->log_finish("Failed to send notification");
        }
    }

    /**
     * Describe the browser and operating system of a user agent, for example "Chrome on Windows".
     *
     * @param string $useragent The user agent string.
     * @param bool $ismoodleapp Whether the login came from the Moodle app.
     * @return string|null The description, or null if neither the browser nor the system is recognised.
     */
    public static function get_device_description(string $useragent, bool $ismoodleapp = false): ?string {
        // Browser and system names are product names, so they are not translated.
        $browsers = [
            'Edge' => '/\bEdg(e|A|iOS)?\//',
            'Opera' => '/\b(OPR|Opera)\b/',
            'Samsung Internet' => '/\bSamsungBrowser\//',
            'Firefox' => '/\b(Firefox|FxiOS)\//',
            'Chrome' => '/\b(Chrome|CriOS)\//',
            'Safari' => '/\bVersion\/[\d.]+.*\bSafari\//',
        ];
        $systems = [
            'Windows' => '/\bWindows\b/',
            'iOS' => '/\b(iPhone|iPad|iPod)\b/',
            'Android' => '/\bAndroid\b/',
            'ChromeOS' => '/\bCrOS\b/',
            'macOS' => '/\b(Macintosh|Mac OS X)\b/',
            'Linux' => '/\bLinux\b/',
        ];

        $browser = null;
        if ($ismoodleapp || str_contains($useragent, 'MoodleMobile')) {
            $browser = get_string('mobileapp', 'tool_mobile');
        } else {
            foreach ($browsers as $name => $pattern) {
                if (preg_match($pattern, $useragent)) {
                    $browser = $name;
                    break;
                }
            }
        }

        $os = null;
        foreach ($systems as $name => $pattern) {
            if (preg_match($pattern, $useragent)) {
                $os = $name;
                break;
            }
        }

        if ($browser !== null && $os !== null) {
            return get_string('newloginnotificationdevice', 'moodle', ['browser' => $browser, 'os' => $os]);
        }
        return $browser ?? $os;
    }

    /**
     * Get the approximate location of an IP address, or the address itself when no location is available.
     *
     * @param string $ip The IP address.
     * @return string The location or IP address line.
     */
    protected static function get_place(string $ip): string {
        global $CFG;

        // Only look up the location when the site has set up IP geolocation.
        if ((!empty($CFG->geoip2file) && file_exists($CFG->geoip2file)) || !empty($CFG->geopluginapikey)) {
            require_once($CFG->dirroot . '/iplookup/lib.php');
            $info = iplookup_find_location($ip);
            $location = implode(', ', array_filter([$info['city'] ?? null, $info['country'] ?? null]));
            if (empty($info['error']) && $location !== '') {
                return get_string('newloginnotificationlocation', 'moodle', s($location));
            }
        }
        return get_string('newloginnotificationip', 'moodle', s($ip));
    }

    /**
     * Check whether the user can turn off the email for this notification.
     *
     * @return bool
     */
    protected static function can_turn_off_emails(): bool {
        $processors = get_message_processors(true);
        if (!isset($processors['email'])) {
            return false;
        }
        $preferences = get_message_output_default_preferences();
        if (!empty($preferences->email_provider_moodle_newlogin_locked)) {
            return false;
        }
        // Same check as the notification preferences page.
        return has_capability('moodle/user:editownmessageprofile', \context_system::instance());
    }
}
