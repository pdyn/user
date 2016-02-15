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
 * A base class for all user objects. Can be used directly.
 */
class User
		extends
			\pdyn\orm\DataObjectAbstract
		implements
			\pdyn\user\UserInterface,
			\pdyn\orm\SearchableObjectInterface
		{

	/** @var string A short, unique name for the user. */
	public $username = '';

	/** @var string A short name for the user, i.e. first name. */
	public $nameshort = '';

	/** @var string A full name for the user, i.e. first and last name. */
	public $namefull = '';

	/** @var int The UNIX timestamp the user was created. */
	public $timecreated = 0;

	/** @var int The UNIX timestamp the user was last updated. */
	public $timeupdated = 0;

	/** @var string|null The user's profile photo. Either a URL/local file, or null if none present. */
	public $image = '';

	/** @var bool Whether the user has been deleted. */
	protected $deleted = false;

	/** @var array Array of user preferences. k=>v format. */
	protected $prefs = null;

	/** @var bool Whether the user is logged in in the current session. */
	protected $logged_in = false;

	/** @var bool Whether the user is an administrator. */
	protected $is_admin = false;

	/** @var bool Whether the user is a guest. */
	protected $is_guest = false;

	/** THe database table that the object resides in. */
	const DB_TABLE = 'users';

	/** @var \pdyn\user\UserSession An active user session. */
	protected $session;

	/**
	 * A hook for subclasses run on login.
	 *
	 * @return bool Success/Failure.
	 */
	protected function loginhook() {
		return true;
	}

	/**
	 * A hook for subclasses run on logout.
	 *
	 * @return bool Success/Failure.
	 */
	protected function logouthook() {
		return true;
	}

	/**
	 * A hook for subclasses run on delete.
	 *
	 * @param array $userinfo Array of user information.
	 * @param array $opts Delete options (i.e. 'remove_all_traces')
	 * @return bool Success/Failure.
	 */
	protected function deletehook($userinfo, $opts) {
		return true;
	}

	/**
	 * A hook for subclasses run on undelete.
	 *
	 * @return bool Success/Failure.
	 */
	protected function undeletehook() {
		return true;
	}

	/**
	 * Set the active session object.
	 *
	 * @param UserSession $session A user session.
	 */
	public function set_session(UserSession $session) {
		$this->session = $session;
		if ($this->id === $this->session->get_userid() && $this->id !== static::GUESTID) {
			$this->logged_in = true;
		}
	}

	/**
	 * Switches the active session and reloads the object's properties.
	 *
	 * @param int $userid A user ID to switch to.
	 * @param bool $persistent Whether the login should be persistent.
	 * @return bool Success/Failure
	 */
	public function login($userid, $persistent) {
		$this->session->login($userid, $persistent);
		$this->id = $userid;
		$this->reload();
		$this->logged_in = (empty($this->id) || $this->id === static::GUESTID) ? false : true;
		$this->loginhook();
		return true;
	}

	/**
	 * Log the user out.
	 *
	 * @param bool $everywhere Whether to just log out the current session, or log out all sessions.
	 */
	public function logout($everywhere = false) {
		if ($this->logged_in === true) {
			if ($everywhere === true) {
				$this->session->logout_everywhere();
			} else {
				$this->session->logout();
			}
			$this->logged_in = false;
			$this->logouthook();
		}
		return true;
	}

	/**
	 * Get user preference.
	 *
	 * @param string $component The component the preference belongs to (i.e. plugin).
	 * @param string $key The preference key to get.
	 * @param string $default The default value to return if preference is not set.
	 * @return mixed The set preference or default value.
	 */
	public function get_pref($component, $key, $default = null) {
		if ($this->prefs === null) {
			$this->load_prefs();
		}
		return (isset($this->prefs[$component][$key])) ? $this->prefs[$component][$key] : $default;
	}

	/**
	 * Load preferences into the object.
	 */
	public function load_prefs() {
		$prefs = $this->DB->get_recordset('users_preferences', ['userid' => $this->id]);
		foreach ($prefs as $pref) {
			$this->prefs[$pref['component']][$pref['preftype']] = $pref['prefvalue'];
		}
	}

	/**
	 * Set user preference.
	 *
	 * @param string $component The component the preference belongs to (i.e. plugin).
	 * @param string $key The preference to set.
	 * @param mixed $value The value to set.
	 */
	public function set_pref($component, $key, $value) {
		if (!is_scalar($value)) {
			throw new Exception('Preference value must be scalar', Exception::ERR_BAD_REQUEST);
		}
		if ($this->prefs === null) {
			$this->load_prefs();
		}
		$timenow = time();
		if (!isset($this->prefs[$component][$key])) {
			$newpref = [
				'userid' => $this->id,
				'component' => $component,
				'preftype' => $key,
				'prefvalue' => $value,
				'timecreated' => $timenow,
				'timeupdated' => $timenow,
			];
			$this->DB->insert_record('users_preferences', $newpref);
		} else {
			$updated = ['prefvalue' => $value, 'timeupdated' => $timenow];
			$where = ['userid' => $this->id, 'component' => $component, 'preftype' => $key];
			$this->DB->update_records('users_preferences', $updated, $where);
		}
		$this->prefs[$component][$key] = $value;
	}

	/**
	 * Search for users based on query string.
	 *
	 * @see \pdyn\orm\SearchableObjectInterface
	 * @param \pdyn\database\DbDriverInterface $DB An active database connection.
	 * @param string $q A query string to search with.
	 * @param int|null $opts An bitmask of options to affect the search results, or null.
	 * @return array An array of users with full names that match the query string.
	 */
	public static function search(\pdyn\database\DbDriverInterface &$DB, $q, $opts = null) {
		$filters = [
			'id != ?',
			'(namefull LIKE ? OR username LIKE ? OR nameshort LIKE ?)',
		];
		$q = '%'.$q.'%';
		$params = [static::GUESTID, $q, $q, $q];
		if (static::opts_contains($opts, static::INCLUDE_DELETED) === false) {
			$filters[] = 'deleted = ?';
			$params[] = 0;
		}

		$sql = 'SELECT id, username, nameshort, namefull, deleted
			      FROM {'.static::DB_TABLE.'}
			     WHERE '.implode(' AND ', $filters).'
			  ORDER BY id ASC';
		return $DB->get_records_sql($sql, $params);
	}

	/**
	 * Return object path (for permissions).
	 *
	 * @return string The object's path.
	 */
	public function get_path() {
		if (empty($this->id)) {
			throw new Exception('Cannot use get_path() on an unsaved object.', Exception::ERR_INTERNAL_ERROR);
		}
		return '/core/users/'.$this->id;
	}

	/**
	 * Populate the class.
	 *
	 * Validates the set ID, if invalid, switches the class to the guest user, and reloads the object.
	 *
	 * @param array $info Array of information to populate into the object.
	 * @return bool Success/Failure.
	 */
	protected function populate(array $info = array()) {
		if (!empty($this->id) && \pdyn\datatype\Id::validate($this->id, true) !== true) {
			$this->id = static::GUESTID;
			$this->reload();
			return false;
		}
		return parent::populate($info);
	}

	/**
	 * Do work after the object is populated.
	 *
	 * This sets some convenience properties, and loads the user's preferences.
	 */
	protected function post_populate() {
		if ($this->id === static::GUESTID) {
			$this->is_guest = true;
		} elseif ($this->id >= 1) {
			$this->is_guest = false;
		}

		if ($this->id === 1) {
			$this->is_admin = true;
		}
	}

	/**
	 * Insert data into the object's database table.
	 *
	 * @param array $info Array of object data, with database column names as keys.
	 * @return int|false The auto-generated record ID or false if failure.
	 */
	protected function db_insert($info) {
		if (empty($info['timecreated']) || \pdyn\datatype\Validator::timestamp($info['timecreated']) !== true) {
			$info['timecreated'] = time();
			$this->timecreated = $info['timecreated'];
		}

		if (empty($info['timeupdated']) || \pdyn\datatype\Validator::timestamp($info['timeupdated']) !== true) {
			$info['timeupdated'] = time();
			$this->timeupdated = $info['timeupdated'];
		}

		return parent::db_insert($info);
	}

	/**
	 * Update the record for the object with the given ID in the object's database table.
	 *
	 * @param int $id The object's ID.
	 * @param array $updated Array of information to update, with column names as keys.
	 */
	protected function db_update($id, $updated) {
		$updated['timeupdated'] = time();
		$this->timeupdated = $updated['timeupdated'];
		return parent::db_update($id, $updated);
	}

	/**
	 * Gets a visible identification for this user based on available information. Uses full name or short name if available.
	 */
	public function get_visible_ident() {
		if (!empty($this->namefull)) {
			$ident = $this->namefull;
		} elseif (!empty($this->nameshort)) {
			$ident = $this->nameshort;
		} else {
			$ident = 'User #'.$this->id;
		}
		return $ident;
	}

	/**
	 * Get an array of user objects based on multiple user IDs.
	 *
	 * @param \pdyn\database\DbDriverInterface $DB A database connection.
	 * @param array $ids Array of user IDs.
	 * @param int $opts Bitmask of options.
	 * @return array Array of user objects for each passed ID, indexed by user id.
	 */
	public static function get_by_ids(\pdyn\database\DbDriverInterface $DB, $ids, $opts = self::NO_OPTS) {
		if (empty($ids)) {
			return [];
		}

		$filters = [];

		if ($ids !== 'all' && !in_array('*', $ids, true)) {
			$filters['id'] = $ids;
		}

		if (static::opts_contains($opts, self::INCLUDE_DELETED) === false) {
			$filters['deleted'] = 0;
		}

		$order = ['id' => 'DESC'];
		$retopts = ['idindexed' => 'id'];
		$users = $DB->get_records(static::DB_TABLE, $filters, $order, '*', 0, null, $retopts);
		foreach ($users as $userid => $user) {
			$userid = (int)$userid;
			$users[$userid] = static::instance_from_record($DB, $user);
		}
		return $users;
	}

	/**
	 * Determine whether a user object is empty or deleted.
	 *
	 * @param \pdyn\user\User $user A user object.
	 * @return bool Whether the user is empty or deleted.
	 */
	public static function empty_or_deleted($user = null) {
		return (!empty($user) && $user instanceof User && $user->deleted === false) ? false : true;
	}

	/**
	 * Get an instance of the guest user.
	 *
	 * @return \pdyn\user\User An instance of the guest user.
	 */
	public static function guest_instance() {
		return static::instance_by_id(self::GUESTID);
	}

	/**
	 * Delete the user.
	 *
	 * @param array $opts If key 'remove_all_traces' is true, user and all associated information will be removed, otherwise
	 *                    the user will just be marked as deleted.
	 * @return bool Success/Failure.
	 */
	public function delete($opts = array()) {
		if ($this->id === 1 || $this->id === static::GUESTID) {
			return false;
		}
		if (empty($this->id)) {
			return false;
		}

		$userinfo = $this->export();

		if (!empty($opts['remove_all_traces']) && $opts['remove_all_traces'] === true) {
			$this->DB->delete_records('online_users', ['user_id' => $this->id]);
			$this->DB->delete_records('users_preferences', ['userid' => $this->id]);
			$this->DB->delete_records(static::DB_TABLE, ['id' => $this->id]);
			$this->id = null;
		} else {
			// Here we'll just mark the account as inactive and pretend it's been deleted while	maintaining the account info.
			// This will be the default action as it preserves history, while preventing the formation of new history.
			$this->set(['deleted' => true]);
			$this->save();
		}

		$this->deletehook($userinfo, $opts);

		return true;
	}

	/**
	 * Attempt to undelete a user that has been "soft" deleted.
	 */
	public function undelete() {
		if (empty($this->id)) {
			return false;
		}
		$this->DB->update_records(static::DB_TABLE, ['deleted' => 0], ['id' => $this->id]);
		$this->deleted = false;
		$this->undeletehook();
	}
}
