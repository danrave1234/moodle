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

use moodle_url;

/**
 * Contains tests for login related notifications.
 *
 * @package    core
 * @copyright  2021 Juan Leyva <juan@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\core\task\send_login_notifications::class)]
final class send_login_notifications_test extends \advanced_testcase {

    /** @var string Firefox on Linux user agent. */
    private const FIREFOX_LINUX = 'Mozilla/5.0 (X11; Linux x86_64; rv:144.0) Gecko/20100101 Firefox/144.0';

    /**
     * Test new login notification.
     */
    public function test_login_notification(): void {
        global $SESSION;

        $this->resetAfterTest();

        \core\session\manager::set_cookies_supported(true);
        $loginuser = self::getDataGenerator()->create_user();
        $this->setUser(0);

        // Mock data for test.
        $loginuser->lastip = '1.2.3.4.6'; // Different ip that current.
        $SESSION->isnewsessioncookie = true; // New session cookie.
        @complete_user_login($loginuser);

        // Redirect messages to sink and stop buffer output from CLI task.
        $sink = $this->redirectMessages();
        ob_start();
        $this->runAdhocTasks(\core\task\send_login_notifications::class);
        ob_get_contents();
        ob_end_clean();
        $messages = $sink->get_messages();
        $sink->close();

        // Send notification, new IP and new session.
        $this->assertCount(1, $messages);
        $this->assertEquals($loginuser->id, $messages[0]->useridto);
        $this->assertEquals('newlogin', $messages[0]->eventtype);
    }

    /**
     * Test new login notification is skipped because of same IP from last login.
     */
    public function test_login_notification_skip_same_ip(): void {
        global $SESSION;

        $this->resetAfterTest();

        $loginuser = self::getDataGenerator()->create_user();
        $this->setUser(0);

        // Mock data for test.
        $SESSION->isnewsessioncookie = true;    // New session cookie.
        @complete_user_login($loginuser);

        // Redirect messages to sink and stop buffer output from CLI task.
        $sink = $this->redirectMessages();
        ob_start();
        $this->runAdhocTasks('\core\task\send_login_notifications');
        $output = ob_get_contents();
        ob_end_clean();
        $messages = $sink->get_messages();
        $sink->close();

        // Skip notification when we have the same previous IP even if the browser used to connect is new.
        $this->assertCount(0, $messages);
    }

    /**
     * Test new login notification is sent when the IP address changes, regardless of session cookie state.
     */
    public function test_login_notification_new_ip(): void {
        global $SESSION;

        $this->resetAfterTest();

        \core\session\manager::set_cookies_supported(true);
        $loginuser = self::getDataGenerator()->create_user();
        $this->setUser(0);

        // Mock data for test.
        $loginuser->lastip = '1.2.3.4'; // Different ip that current.
        $SESSION->isnewsessioncookie = false;
        @complete_user_login($loginuser);

        // Redirect messages to sink and stop buffer output from CLI task.
        $sink = $this->redirectMessages();
        ob_start();
        $this->runAdhocTasks('\core\task\send_login_notifications');
        $output = ob_get_contents();
        ob_end_clean();
        $messages = $sink->get_messages();
        $sink->close();

        // Notification is sent: a different IP with no matching username cookie triggers it.
        $this->assertCount(1, $messages);
        $this->assertEquals($loginuser->id, $messages[0]->useridto);
        $this->assertEquals('newlogin', $messages[0]->eventtype);
    }

    /**
     * Test new login notification is skipped when the same user is logging in again (same username cookie).
     *
     * The IP must be different to ensure $isnewip is true, so we are specifically
     * testing the $issameuser check and not the IP check.
     */
    public function test_login_notification_skip_same_user(): void {
        global $CFG, $SESSION;

        $this->resetAfterTest();

        \core\session\manager::set_cookies_supported(true);
        $CFG->rememberusername = 1;

        $loginuser = self::getDataGenerator()->create_user();
        $this->setUser(0);

        // Set the username cookie to simulate the same user logging in again.
        $cookiename = 'MOODLEID1_' . $CFG->sessioncookie;
        $_COOKIE[$cookiename] = \core\encryption::encrypt($loginuser->username);

        // Mock data for test: different IP and new session cookie, so only $issameuser prevents the notification.
        $loginuser->lastip = '1.2.3.4'; // Different ip from current.
        $SESSION->isnewsessioncookie = true;
        @complete_user_login($loginuser);

        // Redirect messages to sink and stop buffer output from CLI task.
        $sink = $this->redirectMessages();
        ob_start();
        $this->runAdhocTasks('\core\task\send_login_notifications');
        ob_get_contents();
        ob_end_clean();
        $messages = $sink->get_messages();
        $sink->close();

        unset($_COOKIE[$cookiename]);

        // Skip notification: different IP and new session, but same user in cookie.
        $this->assertCount(0, $messages);
    }

    /**
     * Test new login notification is sent when a different user logs in from a new IP,
     * even though there is a username cookie present for a different account.
     *
     * This confirms the $issameuser check uses the cookie value, not just its presence.
     */
    public function test_login_notification_different_user_sends(): void {
        global $CFG, $SESSION;

        $this->resetAfterTest();

        \core\session\manager::set_cookies_supported(true);
        $CFG->rememberusername = 1;

        $loginuser = self::getDataGenerator()->create_user();
        $otheuser  = self::getDataGenerator()->create_user();
        $this->setUser(0);

        // Cookie belongs to a different user.
        $cookiename = 'MOODLEID1_' . $CFG->sessioncookie;
        $_COOKIE[$cookiename] = \core\encryption::encrypt($otheuser->username);

        // Mock data for test: different IP and new session cookie.
        $loginuser->lastip = '1.2.3.4'; // Different ip from current.
        $SESSION->isnewsessioncookie = true;
        @complete_user_login($loginuser);

        // Redirect messages to sink and stop buffer output from CLI task.
        $sink = $this->redirectMessages();
        ob_start();
        $this->runAdhocTasks('\core\task\send_login_notifications');
        ob_get_contents();
        ob_end_clean();
        $messages = $sink->get_messages();
        $sink->close();

        unset($_COOKIE[$cookiename]);

        // Send notification: different IP, new session, and different user in cookie.
        $this->assertCount(1, $messages);
        $this->assertEquals($loginuser->id, $messages[0]->useridto);
        $this->assertEquals('newlogin', $messages[0]->eventtype);
    }


    /**
     * Test new login notification is sent for mobile app logins from a new IP address.
     */
    public function test_login_notification_mobileapp(): void {
        global $SESSION;

        $this->resetAfterTest();

        \core\session\manager::set_cookies_supported(true);
        $loginuser = self::getDataGenerator()->create_user();
        $this->setUser(0);

        // Mock data for test.
        $loginuser->lastip = '1.2.3.4';   // Different ip that current.
        $SESSION->isnewsessioncookie = true;    // New session cookie.
        \core_useragent::instance(true, 'MoodleMobile'); // Force fake mobile app user agent.
        @complete_user_login($loginuser);

        // Redirect messages to sink and stop buffer output from CLI task.
        $sink = $this->redirectMessages();
        ob_start();
        $this->runAdhocTasks('\core\task\send_login_notifications');
        $output = ob_get_contents();
        ob_end_clean();
        $messages = $sink->get_messages();
        $sink->close();

        // Notification is sent: different IP and a mobile app user agent triggers it.
        $this->assertCount(1, $messages);
        $this->assertEquals($loginuser->id, $messages[0]->useridto);
        $this->assertEquals('newlogin', $messages[0]->eventtype);
    }

    /**
     * Test new login notification where the user auth method provides a custom change password URL
     */
    public function test_login_notification_custom_change_password_url(): void {
        global $SESSION;

        $this->resetAfterTest();
        $this->setUser(0);

        // Set LDAP auth change password URL.
        $changepasswordurl = (new moodle_url('/changepassword.php'))->out(false);
        set_config('changepasswordurl', $changepasswordurl, 'auth_ldap');

        $ldapuser = $this->getDataGenerator()->create_user(['auth' => 'ldap']);

        // Mock data for test.
        $ldapuser->lastip = '1.2.3.4';
        $SESSION->isnewsessioncookie = true;
        @complete_user_login($ldapuser);

        // Redirect messages to sink and stop buffer output from CLI task.
        $sink = $this->redirectMessages();
        ob_start();
        $this->runAdhocTasks(send_login_notifications::class);
        ob_end_clean();
        $messages = $sink->get_messages();
        $sink->close();

        // Send notification, assert custom change password URL is present.
        $this->assertCount(1, $messages);
        $expected = "<a href=\"{$changepasswordurl}\">change your password</a>.";
        $this->assertStringContainsString($expected, $messages[0]->fullmessagehtml);
    }

    /**
     * Test new mobile app login notification.
     */
    public function test_mobile_app_login_notification(): void {
        global $USER, $DB, $SESSION;

        $this->resetAfterTest();

        $loginuser = self::getDataGenerator()->create_user();
        $this->setUser($loginuser);

        // Mock data for test.
        $USER->lastip = '1.2.3.4.6'; // Different ip that current.

        $service = $DB->get_record('external_services', array('shortname' => MOODLE_OFFICIAL_MOBILE_SERVICE));
        $token = \core_external\util::generate_token_for_current_user($service);
        \core_useragent::instance(true, 'MoodleMobile'); // Force fake mobile app user agent.

        // Simulate we are using an new device.
        $fakedevice = (object) [
            'userid' => $USER->id,
            'appid' => 'com.moodle.moodlemobile',
            'name' => 'occam',
            'model' => 'Nexus 4',
            'platform' => 'Android',
            'version' => '4.2.2',
            'pushid' => 'kishUhd',
            'uuid' => 'KIhud7s',
            'timecreated' => time() + MINSECS,
            'timemodified' => time() + MINSECS
        ];
        $DB->insert_record('user_devices', $fakedevice);

        \core_external\util::log_token_request($token);

        // Redirect messages to sink and stop buffer output from CLI task.
        $sink = $this->redirectMessages();
        ob_start();
        $this->runAdhocTasks('\core\task\send_login_notifications');
        $output = ob_get_contents();
        ob_end_clean();
        $messages = $sink->get_messages();
        $sink->close();

        // We sent a login notification because we are using a new device and different IP.
        $this->assertCount(1, $messages);
        $this->assertEquals($loginuser->id, $messages[0]->useridto);
        $this->assertEquals('newlogin', $messages[0]->eventtype);
    }

    /**
     * Test new mobile app login notification skipped becase of same last ip.
     */
    public function test_mobile_app_login_notification_skip_same_ip(): void {
        global $USER, $DB, $SESSION;

        $this->resetAfterTest();

        $loginuser = self::getDataGenerator()->create_user();
        $this->setUser($loginuser);

        // Mock data for test.
        $USER->lastip = '0.0.0.0';
        $service = $DB->get_record('external_services', array('shortname' => MOODLE_OFFICIAL_MOBILE_SERVICE));
        $token = \core_external\util::generate_token_for_current_user($service);
        \core_useragent::instance(true, 'MoodleMobile'); // Force fake mobile app user agent.

        // Simulate we are using an new device.
        $fakedevice = (object) [
            'userid' => $USER->id,
            'appid' => 'com.moodle.moodlemobile',
            'name' => 'occam',
            'model' => 'Nexus 4',
            'platform' => 'Android',
            'version' => '4.2.2',
            'pushid' => 'kishUhd',
            'uuid' => 'KIhud7s',
            'timecreated' => time() + MINSECS,
            'timemodified' => time() + MINSECS
        ];
        $DB->insert_record('user_devices', $fakedevice);

        \core_external\util::log_token_request($token);

        // Redirect messages to sink and stop buffer output from CLI task.
        $sink = $this->redirectMessages();
        ob_start();
        $this->runAdhocTasks('\core\task\send_login_notifications');
        $output = ob_get_contents();
        ob_end_clean();
        $messages = $sink->get_messages();
        $sink->close();

        // While using the same IP avoid sending new login notifications even if we are using a new device.
        $this->assertCount(0, $messages);
    }

    /**
     * Test new mobile app login notification skipped becase of same device.
     */
    public function test_mobile_app_login_notification_skip_same_device(): void {
        global $USER, $DB, $SESSION;

        $this->resetAfterTest();

        $loginuser = self::getDataGenerator()->create_user();
        $this->setUser($loginuser);

        // Mock data for test.
        $USER->lastip = '1.2.3.4.6';    // New ip.
        $service = $DB->get_record('external_services', array('shortname' => MOODLE_OFFICIAL_MOBILE_SERVICE));
        $token = \core_external\util::generate_token_for_current_user($service);
        \core_useragent::instance(true, 'MoodleMobile'); // Force fake mobile app user agent.

        \core_external\util::log_token_request($token);

        // Redirect messages to sink and stop buffer output from CLI task.
        $sink = $this->redirectMessages();
        ob_start();
        $this->runAdhocTasks('\core\task\send_login_notifications');
        $output = ob_get_contents();
        ob_end_clean();
        $messages = $sink->get_messages();
        $sink->close();

        // While using the same device avoid sending new login notifications even if the IP changes.
        $this->assertCount(0, $messages);
    }

    /**
     * Log a user in from a new IP address with the given user agent and return the notifications sent.
     *
     * @param \stdClass $user The user to log in.
     * @param string $useragent The user agent to log in with.
     * @return array The messages sent.
     */
    protected function login_and_get_notifications(\stdClass $user, string $useragent): array {
        global $SESSION;

        \core\session\manager::set_cookies_supported(true);
        $this->setUser(0);
        $user->lastip = '1.2.3.4';
        $SESSION->isnewsessioncookie = true;
        \core_useragent::instance(true, $useragent);
        @complete_user_login($user);

        $sink = $this->redirectMessages();
        ob_start();
        $this->runAdhocTasks(send_login_notifications::class);
        ob_end_clean();
        $messages = $sink->get_messages();
        $sink->close();

        return $messages;
    }

    /**
     * Test the content of the new login notification.
     */
    public function test_login_notification_content(): void {
        $this->resetAfterTest();

        $user = self::getDataGenerator()->create_user(['firstname' => 'Ana', 'timezone' => 'Europe/Madrid']);
        $chromewindows = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) ' .
            'Chrome/141.0.0.0 Safari/537.36';
        $messages = $this->login_and_get_notifications($user, $chromewindows);

        $this->assertCount(1, $messages);
        $message = $messages[0];
        $this->assertEquals('New login to PHPUnit test site from Chrome on Windows', $message->subject);
        $expected = 'New login to PHPUnit test site from Chrome on Windows. Not you? Review your browser sessions.';
        $this->assertEquals($expected, $message->smallmessage);

        $html = $message->fullmessagehtml;
        $this->assertStringContainsString('<p>Hi Ana,</p>', $html);
        $this->assertStringContainsString('from a new network and a browser we haven\'t seen before.', $html);
        $this->assertStringContainsString('<li><strong>Browser:</strong> Chrome on Windows</li>', $html);
        $this->assertStringContainsString('(Europe/Madrid)</li>', $html);
        // No IP geolocation is set up, so the IP address is shown instead of the location.
        $this->assertStringContainsString('<li><strong>IP address:</strong> 0.0.0.0</li>', $html);
        $this->assertStringNotContainsString('Location:', $html);
        $this->assertStringContainsString((new moodle_url('/report/usersessions/user.php'))->out(false), $html);
        $this->assertStringContainsString((new moodle_url('/login/change_password.php'))->out(false), $html);
        $this->assertStringNotContainsString($chromewindows, $html);
        // The email is locked on by default, so users are not told they can turn it off.
        $this->assertStringNotContainsString('notification preferences', $html);
    }

    /**
     * Test the new login notification for a user agent that is not recognised.
     */
    public function test_login_notification_unknown_device(): void {
        $this->resetAfterTest();

        $user = self::getDataGenerator()->create_user();
        $messages = $this->login_and_get_notifications($user, 'curl/8.5.0');

        $this->assertCount(1, $messages);
        $this->assertEquals('New login to PHPUnit test site', $messages[0]->subject);
        // The browser line is left out rather than showing an unknown browser.
        $this->assertStringNotContainsString('Browser:', $messages[0]->fullmessagehtml);
        $this->assertStringContainsString('<ul>' . "\n" . '<li><strong>When:</strong>', $messages[0]->fullmessagehtml);
    }

    /**
     * Test the opt-out sentence is shown when the user can turn off the email.
     */
    public function test_login_notification_email_unlocked(): void {
        $this->resetAfterTest();

        set_config('email_provider_moodle_newlogin_locked', 0, 'message');
        $user = self::getDataGenerator()->create_user();
        $messages = $this->login_and_get_notifications($user, self::FIREFOX_LINUX);

        $this->assertCount(1, $messages);
        $preferencesurl = (new moodle_url('/message/notificationpreferences.php', ['userid' => $user->id]))->out(false);
        $this->assertStringContainsString('You can turn off these emails in your <a href="' . $preferencesurl .
            '">notification preferences</a>.', $messages[0]->fullmessagehtml);
    }

    /**
     * Test the browser sessions link is left out when the user cannot manage their sessions.
     */
    public function test_login_notification_no_sessions_access(): void {
        global $CFG;

        $this->resetAfterTest();

        $syscontext = \context_system::instance();
        assign_capability('report/usersessions:manageownsessions', CAP_PROHIBIT, $CFG->defaultuserroleid, $syscontext, true);
        $user = self::getDataGenerator()->create_user();
        $messages = $this->login_and_get_notifications($user, self::FIREFOX_LINUX);

        $this->assertCount(1, $messages);
        $this->assertEquals('New login to PHPUnit test site from Firefox on Linux', $messages[0]->smallmessage);
        $this->assertStringNotContainsString('/report/usersessions/user.php', $messages[0]->fullmessagehtml);
        $this->assertStringContainsString('>Change your password</a>.', $messages[0]->fullmessagehtml);
    }

    /**
     * Test describing the browser and system of a user agent.
     *
     * @param string $useragent The user agent.
     * @param bool $ismoodleapp Whether the login came from the Moodle app.
     * @param string|null $expected The expected description.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('device_description_provider')]
    public function test_get_device_description(string $useragent, bool $ismoodleapp, ?string $expected): void {
        $this->assertSame($expected, send_login_notifications::get_device_description($useragent, $ismoodleapp));
    }

    /**
     * Data provider for {@see test_get_device_description()}.
     *
     * @return array
     */
    public static function device_description_provider(): array {
        return [
            'Chrome on Windows' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36',
                false,
                'Chrome on Windows',
            ],
            'Edge on Windows' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 ' .
                    'Safari/537.36 Edg/141.0.0.0',
                false,
                'Edge on Windows',
            ],
            'Opera on Windows' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 ' .
                    'Safari/537.36 OPR/123.0.0.0',
                false,
                'Opera on Windows',
            ],
            'Safari on macOS' => [
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.6 ' .
                    'Safari/605.1.15',
                false,
                'Safari on macOS',
            ],
            'Safari on iOS' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) ' .
                    'Version/18.6 Mobile/15E148 Safari/604.1',
                false,
                'Safari on iOS',
            ],
            'Chrome on Android' => [
                'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 ' .
                    'Mobile Safari/537.36',
                false,
                'Chrome on Android',
            ],
            'Firefox on Linux' => [
                'Mozilla/5.0 (X11; Linux x86_64; rv:144.0) Gecko/20100101 Firefox/144.0',
                false,
                'Firefox on Linux',
            ],
            'Chrome on ChromeOS' => [
                'Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 ' .
                    'Safari/537.36',
                false,
                'Chrome on ChromeOS',
            ],
            'Moodle app on Android' => [
                'Mozilla/5.0 (Linux; Android 15; Pixel 9; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 ' .
                    'Chrome/141.0.0.0 Mobile Safari/537.36 MoodleMobile 5.1.0 (51000)',
                true,
                'Mobile app on Android',
            ],
            'Moodle app, no system' => ['MoodleMobile', true, 'Mobile app'],
            'System only' => ['SomeBot/1.0 (Windows NT 10.0)', false, 'Windows'],
            'Unknown' => ['curl/8.5.0', false, null],
            'Empty' => ['', false, null],
        ];
    }
}
