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
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_forum\local\vaults\discussion_list;

/**
 * Web service to get the discussion list of a forum.
 *
 * @package   mod_forum
 * @category  external
 * @copyright 2026 Danrave Keh
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_discussion_list extends external_api {
    /** @var int The largest page size allowed, unless the site's "forum_manydiscussions" setting is larger. */
    public const MAX_PERPAGE = 100;

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'forumid' => new external_value(PARAM_INT, 'Forum instance id'),
            'sortorder' => new external_value(
                PARAM_INT,
                'Sort order, one of the discussion list vault SORTORDER_* constants (1-12)',
                VALUE_DEFAULT,
                null,
                NULL_ALLOWED,
            ),
            'page' => new external_value(PARAM_INT, 'Page number, starting from 0', VALUE_DEFAULT, 0),
            'perpage' => new external_value(
                PARAM_INT,
                'Number of discussions per page, from 1 to the maximum page size',
                VALUE_DEFAULT,
                null,
                NULL_ALLOWED,
            ),
            'groupid' => new external_value(PARAM_INT, 'Group id', VALUE_DEFAULT, null, NULL_ALLOWED),
        ]);
    }

    /**
     * Get the discussion list of a forum.
     *
     * @param int $forumid The forum instance id.
     * @param int|null $sortorder The sort order, or null for the default.
     * @param int $page The page number, starting from 0.
     * @param int|null $perpage The number of discussions per page, or null for the default.
     * @param int|null $groupid The group id, or null for the default.
     * @return array The discussion list.
     */
    public static function execute(
        int $forumid,
        ?int $sortorder = null,
        int $page = 0,
        ?int $perpage = null,
        ?int $groupid = null,
    ): array {
        global $CFG, $PAGE, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'forumid' => $forumid,
            'sortorder' => $sortorder,
            'page' => $page,
            'perpage' => $perpage,
            'groupid' => $groupid,
        ]);

        $vaultfactory = \mod_forum\local\container::get_vault_factory();
        $forum = $vaultfactory->get_forum_vault()->get_from_id($params['forumid']);
        if (empty($forum)) {
            throw new \moodle_exception('invalidforumid', 'forum');
        }

        $context = $forum->get_context();
        if ($forum->get_course_id() == SITEID && empty($CFG->forcelogin) && (!isloggedin() || isguestuser())) {
            // As in view.php, visitors who are not logged in can view a site home forum, unless the site forces login.
            // require_course_login() still asks for a login when the forum is hidden.
            [, $course, $cm] = get_context_info_array($context->id);
            require_course_login($course, true, $cm, false, true);
            $PAGE->set_context($context);
        } else {
            // Checks the course login and the activity visibility, including hidden activities.
            self::validate_context($context);
        }

        $capabilitymanager = \mod_forum\local\container::get_manager_factory()->get_capability_manager($forum);
        if (!$capabilitymanager->can_view_discussions($USER)) {
            throw new \moodle_exception('noviewdiscussionspermission', 'forum');
        }

        if ($params['sortorder'] !== null && !in_array($params['sortorder'], self::get_sort_orders(), true)) {
            throw new \invalid_parameter_exception('Invalid value for sortorder parameter: ' . $params['sortorder']);
        }
        if ($params['page'] < 0) {
            throw new \invalid_parameter_exception('Invalid value for page parameter: ' . $params['page']);
        }
        if ($params['perpage'] !== null && ($params['perpage'] < 1 || $params['perpage'] > self::get_max_perpage())) {
            throw new \invalid_parameter_exception('Invalid value for perpage parameter: ' . $params['perpage']);
        }

        // TODO MDL-90423 Filter the discussions by group.
        return [
            'totalcount' => 0,
            'page' => $params['page'],
            'perpage' => $params['perpage'],
            'sortorder' => $params['sortorder'],
        ];
    }

    /**
     * Describe the return structure of the external service.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'totalcount' => new external_value(PARAM_INT, 'Total number of discussions in the forum'),
            'page' => new external_value(PARAM_INT, 'Page number, starting from 0'),
            'perpage' => new external_value(PARAM_INT, 'Number of discussions per page', VALUE_REQUIRED, null, NULL_ALLOWED),
            'sortorder' => new external_value(PARAM_INT, 'Sort order', VALUE_REQUIRED, null, NULL_ALLOWED),
        ]);
    }

    /**
     * The largest page size allowed, so that one request cannot load every discussion of a large forum.
     *
     * @return int
     */
    public static function get_max_perpage(): int {
        global $CFG;
        return max(self::MAX_PERPAGE, (int) ($CFG->forum_manydiscussions ?? 0));
    }

    /**
     * The sort orders the discussion list supports.
     *
     * @return int[]
     */
    private static function get_sort_orders(): array {
        return [
            discussion_list::SORTORDER_LASTPOST_DESC,
            discussion_list::SORTORDER_LASTPOST_ASC,
            discussion_list::SORTORDER_CREATED_DESC,
            discussion_list::SORTORDER_CREATED_ASC,
            discussion_list::SORTORDER_REPLIES_DESC,
            discussion_list::SORTORDER_REPLIES_ASC,
            discussion_list::SORTORDER_DISCUSSION_DESC,
            discussion_list::SORTORDER_DISCUSSION_ASC,
            discussion_list::SORTORDER_STARTER_DESC,
            discussion_list::SORTORDER_STARTER_ASC,
            discussion_list::SORTORDER_GROUP_DESC,
            discussion_list::SORTORDER_GROUP_ASC,
        ];
    }
}
