<?php
/*
This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

/**
 * @copyright 2010 onwards James McQuillan (http://pdyn.net)
 * @author James McQuillan <james@pdyn.net>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace pdyn\user;

use \pdyn\base\Exception;

/**
 * A test database schema.
 */
class DbSchema extends \pdyn\database\DbSchema {
	public static function online_users() {
		return [
			'columns' => [
				'sess_id' => 'str',
				'sess_data' => 'text',
				'sess_created' => 'timestamp',
				'sess_updated' => 'timestamp',
				'sess_expire' => 'timestamp',
				'user_id' => 'user_id',
				'user_ip' => 'str',
				'cur_script_filename' => 'text',
				'cur_req_uri' => 'text',
				'sess_persistent' => 'bool',
				'sess_invalid' => 'bool',
			],
			'keys' => [
				'PRIMARY' => ['sess_id', true],
			],
		];
	}

	public static function users() {
		return [
			'columns' => [
				'id' => 'user_id',
				'username' => 'str',
				'nameshort' => 'text',
				'namefull' => 'text',
				'image' => 'text',
				'deleted' => 'bool',
				'timecreated' => 'timestamp',
				'timeupdated' => 'timestamp',
			],
			'keys' => [
				'PRIMARY' => ['id', true],
				'username' => ['username', true],
			],
		];
	}

	public static function users_preferences() {
		return [
			'columns' => [
				'id' => 'id',
				'userid' => 'user_id',
				'component' => 'str',
				'preftype' => 'str',
				'prefvalue' => 'text',
				'timecreated' => 'timestamp',
				'timeupdated' => 'timestamp',
			],
			'keys' => [
				'PRIMARY' => ['id', true],
				'user_idx' => ['userid', false],
				'usrpref_idx' => ['userid,preftype', true],
			],
		];
	}

	public static function userclasses() {
		return [
			'columns' => [
				'id' => 'id',
				'name' => 'text',
			],
			'keys' => [
				'PRIMARY' => ['id', true],
			],
		];
	}

	public static function userclasses_assignments() {
		return [
			'columns' => [
				'id' => 'id',
				'userclass_id' => 'id',
				'user_id' => 'user_id',
			],
			'keys' => [
				'PRIMARY' => ['id', true],
				'classid' => ['userclass_id', false],
				'userid' => ['user_id', false],
			],
		];
	}
}
