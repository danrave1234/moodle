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

namespace mod_forum\external;

use core_external\external_api;
use mod_forum\local\vaults\discussion_list;

/**
 * Tests for the get_discussion_list external function.
 *
 * @package    mod_forum
 * @category   test
 * @copyright  2026 Danrave Keh
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(get_discussion_list::class)]
final class get_discussion_list_test extends \core_external\tests\externallib_testcase {
    /**
     * Create a course with a forum and a student enrolled in it.
     *
     * @param array $forumrecord Extra settings for the forum.
     * @return array The course, the forum and the student.
     */
    protected function create_forum_with_student(array $forumrecord = []): array {
        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id] + $forumrecord);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        return [$course, $forum, $student];
    }

    /**
     * Test that an enrolled student gets the empty structure, with the given values.
     */
    public function test_execute_returns_empty_structure(): void {
        $this->resetAfterTest();
        [, $forum, $student] = $this->create_forum_with_student();
        $this->setUser($student);

        $result = get_discussion_list::execute($forum->id, discussion_list::SORTORDER_GROUP_ASC, 2, 10);
        $result = external_api::clean_returnvalue(get_discussion_list::execute_returns(), $result);

        $this->assertSame([
            'totalcount' => 0,
            'page' => 2,
            'perpage' => 10,
            'sortorder' => discussion_list::SORTORDER_GROUP_ASC,
        ], $result);
    }

    /**
     * Test the default values when only the forum id is given.
     */
    public function test_execute_defaults(): void {
        $this->resetAfterTest();
        [, $forum, $student] = $this->create_forum_with_student();
        $this->setUser($student);

        $result = get_discussion_list::execute($forum->id);
        $result = external_api::clean_returnvalue(get_discussion_list::execute_returns(), $result);

        $this->assertSame([
            'totalcount' => 0,
            'page' => 0,
            'perpage' => null,
            'sortorder' => null,
        ], $result);
    }

    /**
     * Test that the function is registered as an ajax web service.
     */
    public function test_execute_through_ajax(): void {
        $this->resetAfterTest();
        [, $forum, $student] = $this->create_forum_with_student();
        $this->setUser($student);
        // Ajax requests always send the session key.
        $_POST['sesskey'] = sesskey();

        $result = external_api::call_external_function('mod_forum_get_discussion_list', ['forumid' => $forum->id], true);

        $this->assertFalse($result['error']);
        $this->assertSame(0, $result['data']['totalcount']);
    }

    /**
     * Test that an unknown forum id is rejected.
     */
    public function test_execute_unknown_forum(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('invalidforumid', 'forum'));
        get_discussion_list::execute(-1);
    }

    /**
     * Test that a user who is not enrolled in the course is rejected.
     */
    public function test_execute_not_enrolled(): void {
        $this->resetAfterTest();
        [, $forum] = $this->create_forum_with_student();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\require_login_exception::class);
        get_discussion_list::execute($forum->id);
    }

    /**
     * Test that a student cannot get the list of a hidden forum, but a teacher can.
     */
    public function test_execute_hidden_forum(): void {
        $this->resetAfterTest();
        [$course, $forum, $student] = $this->create_forum_with_student(['visible' => 0]);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $this->setUser($teacher);
        $result = get_discussion_list::execute($forum->id);
        $this->assertSame(0, $result['totalcount']);

        $this->setUser($student);
        $this->expectException(\require_login_exception::class);
        get_discussion_list::execute($forum->id);
    }

    /**
     * Test who can get the list of a site home forum without logging in, as in view.php.
     *
     * @param string $user 'none' for a visitor who is not logged in, or 'guest' for the guest user.
     * @param bool $forcelogin Whether "Force users to log in" is on.
     * @param bool $visible Whether the forum is visible.
     * @param bool $allowed Whether the visitor can get the list.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('site_home_visitor_provider')]
    public function test_execute_site_home_visitor(string $user, bool $forcelogin, bool $visible, bool $allowed): void {
        $this->resetAfterTest();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => SITEID, 'visible' => (int) $visible]);
        set_config('forcelogin', (int) $forcelogin);
        if ($user === 'guest') {
            $this->setGuestUser();
        } else {
            $this->setUser(null);
        }

        if (!$allowed) {
            $this->expectException(\require_login_exception::class);
        }
        $result = get_discussion_list::execute($forum->id);
        $result = external_api::clean_returnvalue(get_discussion_list::execute_returns(), $result);
        $this->assertSame(0, $result['totalcount']);
    }

    /**
     * Provides the site home visitor cases.
     *
     * @return array
     */
    public static function site_home_visitor_provider(): array {
        return [
            'Not logged in, visible forum' => ['none', false, true, true],
            'Guest, visible forum' => ['guest', false, true, true],
            'Not logged in, force login on' => ['none', true, true, false],
            'Not logged in, hidden forum' => ['none', false, false, false],
            'Guest, hidden forum' => ['guest', false, false, false],
        ];
    }

    /**
     * Test that a visitor who is not logged in cannot get the list of a forum in a course.
     */
    public function test_execute_course_forum_not_logged_in(): void {
        $this->resetAfterTest();
        [, $forum] = $this->create_forum_with_student();
        set_config('forcelogin', 0);
        $this->setUser(null);

        $this->expectException(\require_login_exception::class);
        get_discussion_list::execute($forum->id);
    }

    /**
     * Test that a visitor who is not logged in can call the function through ajax on the site home.
     */
    public function test_execute_site_home_through_ajax_not_logged_in(): void {
        $this->resetAfterTest();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => SITEID]);
        set_config('forcelogin', 0);
        $this->setUser(null);

        $result = external_api::call_external_function('mod_forum_get_discussion_list', ['forumid' => $forum->id], true);

        $this->assertFalse($result['error'], $result['exception']->message ?? '');
        $this->assertSame(0, $result['data']['totalcount']);
    }

    /**
     * Test that a visitor who is not logged in needs the capability to view discussions.
     */
    public function test_execute_site_home_not_logged_in_without_capability(): void {
        global $CFG;

        $this->resetAfterTest();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => SITEID]);
        assign_capability(
            'mod/forum:viewdiscussion',
            CAP_PROHIBIT,
            $CFG->notloggedinroleid,
            \context_module::instance($forum->cmid),
        );
        set_config('forcelogin', 0);
        $this->setUser(null);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('noviewdiscussionspermission', 'forum'));
        get_discussion_list::execute($forum->id);
    }

    /**
     * Test that a user without the capability to view discussions is rejected.
     */
    public function test_execute_without_capability(): void {
        global $DB;

        $this->resetAfterTest();
        [, $forum, $student] = $this->create_forum_with_student();
        $studentrole = $DB->get_field('role', 'id', ['shortname' => 'student']);
        assign_capability('mod/forum:viewdiscussion', CAP_PROHIBIT, $studentrole, \context_module::instance($forum->cmid));
        $this->setUser($student);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('noviewdiscussionspermission', 'forum'));
        get_discussion_list::execute($forum->id);
    }

    /**
     * Test that invalid values are rejected.
     *
     * @param int|null $sortorder The sort order.
     * @param int $page The page number.
     * @param int|null $perpage The number of discussions per page.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_values_provider')]
    public function test_execute_invalid_values(?int $sortorder, int $page, ?int $perpage): void {
        $this->resetAfterTest();
        [, $forum, $student] = $this->create_forum_with_student();
        $this->setUser($student);

        $this->expectException(\invalid_parameter_exception::class);
        get_discussion_list::execute($forum->id, $sortorder, $page, $perpage);
    }

    /**
     * Provides invalid sort orders, pages and page sizes.
     *
     * @return array
     */
    public static function invalid_values_provider(): array {
        return [
            'Sort order 0' => [0, 0, null],
            'Sort order 13' => [13, 0, null],
            'Negative page' => [null, -1, null],
            'Negative page size' => [null, 0, -1],
            'Page size 0' => [null, 0, 0],
            'Page size above the maximum' => [null, 0, get_discussion_list::MAX_PERPAGE + 1],
        ];
    }

    /**
     * Test the page size limits, including a site setting larger than the default maximum.
     */
    public function test_execute_perpage_limits(): void {
        $this->resetAfterTest();
        [, $forum, $student] = $this->create_forum_with_student();
        $this->setUser($student);

        set_config('forum_manydiscussions', 20);
        $this->assertSame(1, get_discussion_list::execute($forum->id, null, 0, 1)['perpage']);
        $this->assertSame(
            get_discussion_list::MAX_PERPAGE,
            get_discussion_list::execute($forum->id, null, 0, get_discussion_list::MAX_PERPAGE)['perpage'],
        );

        // A site that shows more discussions per page can also request them.
        set_config('forum_manydiscussions', 150);
        $this->assertSame(150, get_discussion_list::execute($forum->id, null, 0, 150)['perpage']);
        $this->expectException(\invalid_parameter_exception::class);
        get_discussion_list::execute($forum->id, null, 0, 151);
    }
}
