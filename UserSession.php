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

/*
 * Sessions
 */
class UserSession implements \SessionHandlerInterface {
	/** @var \pdyn\database\DbDriverInterface An active database connection. */
	protected $DB;

	/** @var int The number of seconds the session is valid for. */
	protected $expire_time = 3600;

	/** @var int The active user's database ID. */
	protected $userid;

	/**
	 * Constructor.
	 *
	 * @param \pdyn\database\DbDriverInterface &$DB An active database connection.
	 * @param string $sessionname The name of the session.
	 */
	public function __construct(\pdyn\database\DbDriverInterface &$DB, $sessionname = 'USERSESS') {
		$this->DB =& $DB;
		$this->sessionname = $sessionname;

		ini_set('session.use_only_cookies', true);
		ini_set('session.name', $sessionname);
		ini_set('session.cookie_httponly', true);
		ini_set('session.cookie_lifetime', 0);
		//ini_set('session.cookie_domain',$_SERVER['HTTP_HOST']); //this causes problems sometimes.
		ini_set('session.hash_function', 1);
		ini_set('session.hash_bits_per_character', 6);
	}

	/**
	 * Register this class as the session handler.
	 */
	public function register_save_handler() {
		session_set_save_handler(
			[$this, 'open'],
			[$this, 'close'],
			[$this, 'read'],
			[$this, 'write'],
			[$this, 'destroy'],
			[$this, 'gc']
		);
	}

	/**
	 * Open the session.
	 *
	 * In this implementation, this doesn't do anything.
	 *
	 * @param string $save_path The path where to store/retrieve the session.
	 * @param string $session_name The session name.
	 * @return bool Success/Failure.
	 */
	public function open($save_path, $session_name) {
		return true;
	}

	/**
	 * Close the session.
	 *
	 * @return bool Success/Failure.
	 */
	public function close() {
		$this->gc($this->expire_time);
		return true;
	}

	/**
	 * Read the session.
	 *
	 * @param string $sessid The session ID.
	 * @return string The encoded session data.
	 */
	public function read($sessid) {
		$session_info = $this->DB->get_record('online_users', ['sess_id' => $sessid]);
		if (!empty($session_info)) {
			if (!empty($session_info['sess_invalid']) && $session_info['sess_invalid'] == 1) {
				$p = session_get_cookie_params();
				setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
			} else {
				if (isset($session_info['sess_data'])) {
					return $session_info['sess_data'];
				}
			}
		}
		return '';
	}

	/**
	 * Write the session.
	 *
	 * @param string $sess_id The session ID.
	 * @param string $session_data The encoded session data.
	 * @param bool $exp Session expiry time.
	 * @return bool Success/Failure.
	 */
	public function write($sess_id, $session_data, $exp = false) {
		if (empty($sess_id)) {
			return false;
		}

		$new_exp = time();
		$new_exp += (!empty($exp) && is_int($exp)) ? $exp : $this->expire_time;

		//get the user id of the session
		$sess = $this->DB->get_record('online_users', ['sess_id' => $sess_id]);

		$sess_new_info = [
			'sess_data' => $session_data,
			'sess_expire' => $new_exp,
			'user_id' => $this->userid,
			'user_ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1',
			'cur_script_filename' => $_SERVER['SCRIPT_FILENAME'],
			'cur_req_uri' => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'unknown',
			'sess_updated' => time(),
		];

		if (empty($sess)) {
			//insert
			$sess_new_info['sess_id'] = $sess_id;
			$sess_new_info['sess_created'] = time();
			$this->DB->insert_record('online_users', $sess_new_info);
		} else {
			if (!empty($sess['sess_invalid']) && $sess['sess_invalid'] == 1) {
				return false;
			} else {
				$where = ['sess_id' => $sess_id];
				$this->DB->update_records('online_users', $sess_new_info, $where);
			}
		}

		return true;
	}

	/**
	 * Destroy a session.
	 *
	 * @param string $sess_id The session ID.
	 * @return bool Success/Failure.
	 */
	public function destroy($sess_id) {
		$this->DB->update_records('online_users', ['sess_invalid' => 1], ['sess_id' => $sess_id]);
		return true;
	}

	/**
	 * Perform session garbage collection.
	 *
	 * @param string $maxlifetime Maximum number of seconds of a session's life before it's deleted.
	 * @return bool Success/Failure.
	 */
	public function gc($maxlifetime) {
		$this->DB->delete_records_select('online_users', 'sess_expire < ?', [time()]);
		return true;
	}

	/**
	 * Initialize a user session with minimal input.
	 *
	 * @param \pdyn\database\DbDriverInterface &$DB An active database connection.
	 * @return \pdyn\user\User The user object that is logged in.
	 */
	public static function init(\pdyn\database\DbDriverInterface &$DB) {
		$sess = new static($DB);
		$sess->register_save_handler();

		session_cache_limiter('nocache');
		if (session_status() === PHP_SESSION_NONE) {
			session_start();
		}

		$USR = $sess->get_user();
		$USR->set_session($sess);
		return $USR;
	}

	/**
	 * Set the ID of the currently logged in user.
	 *
	 * @param int $userid A userid.
	 */
	public function set_userid($userid) {
		$this->userid = (int)$userid;
	}

	/**
	 * Set the ID of the currently logged in user.
	 *
	 * @param int $userid A userid.
	 */
	public function get_userid() {
		return $this->userid;
	}

	/**
	 * Get the user object of the user that is logged in with this session.
	 *
	 * @return \pdyn\user\User The user object that is logged in.
	 */
	public function get_user() {
		$this->set_userid(UserInterface::GUESTID);
		if (!empty($_SESSION['user']) && \pdyn\datatype\Id::validate($_SESSION['user'], false) === true) {
			// Active session.
			$this->set_userid($_SESSION['user']);
		} else {
			// Check persistant login cookie.
			$pcookienane = $this->get_persistent_login_cookie_name();
			if (!empty($_COOKIE[$pcookienane])) {
				$sess_id = $_COOKIE[$pcookienane];
				$conditions = ['sess_id' => $sess_id, 'sess_invalid' => 0, 'sess_persistent' => 1];
				$session = $this->DB->get_record('online_users', $conditions);
				if (!empty($session) && !empty($session['user_id']) && \pdyn\datatype\Id::validate($session['user_id'], false) === true) {
					$this->login($session['user_id'], false);
				}
			}
		}
		return $this->constructuser($this->userid);
	}

	/**
	 * Construct a user class.
	 * @param int $userid The ID of the user to construct.
	 * @return \pdyn\user\User The constructed user.
	 */
	protected function constructuser($userid) {
		return User::instance_by_id($userid, true);
	}

	/**
	 * Generate a unique session ID.
	 *
	 * @return string A unique 64-character string.
	 */
	protected function generate_session_id() {
		return \pdyn\base\Utils::uniqid(64);
	}

	/**
	 * Get the name of the cookie we will store persistent session information in.
	 *
	 * @return string The name of the persistent login cookie.
	 */
	protected function get_persistent_login_cookie_name() {
		return $this->sessionname.'_persist';
	}

	/**
	 * Start a session for a given user, and optionally make it persistent.
	 *
	 * @param int $userid A user id to start the session for.
	 * @param  bool $persistent Whether to make the session persistent.
	 * @return bool Success/Failure.
	 */
	public function login($userid, $persistent = false) {
		global $APP;
		$this->set_userid($userid);

		$_SESSION['user'] = $this->userid;

		// Set persistant check if required.
		if ($persistent === true) {
			$pcookienane = $this->get_persistent_login_cookie_name();
			$sess_id = $this->generate_session_id();
			$expire = time() + (60 * 60 * 24 * 365);

			$onlineuserrec = [
				'sess_id' => $sess_id,
				'sess_data' => '',
				'user_id' => $this->userid,
				'sess_expire' => $expire,
				'sess_persistent' => 1,
			];
			$this->DB->insert_record('online_users', $onlineuserrec);

			$APP->set_site_cookie($pcookienane, $sess_id);
		}

		return true;
	}

	/**
	 * Destroy the current session.
	 *
	 * @return bool Success/Failure.
	 */
	public function logout() {
		global $APP;
		$pcookienane = $this->get_persistent_login_cookie_name();

		$_SESSION = [];
		if (ini_get('session.use_cookies')) {
			$p = session_get_cookie_params();
			setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
		}
		session_destroy();

		if (isset($_COOKIE[$pcookienane])) {
			//destry persistent session
			$this->DB->delete_records('online_users', ['sess_id' => $_COOKIE[$pcookienane], 'sess_persistent' => 1]);
			$APP->destroy_site_cookie($pcookienane);
		}

		return true;
	}

	/**
	 * Destroy all sessions for the current user.
	 *
	 * @return bool Success/Failure
	 */
	public function logout_everywhere() {
		$this->logout();
		$this->DB->delete_records('online_users', ['user_id' => $this->userid]);
		return true;
	}

	/**
	 * Manually destroy a session.
	 *
	 * @param \pdyn\database\DbDriverInterface $DB An active database connection.
	 * @param string $sess_id The id of the session to destroy.
	 * @return bool Success/Failure.
	 */
	public static function destroy_sess_manual(\pdyn\database\DbDriverInterface &$DB, $sess_id) {
		if (empty($sess_id)) {
			return false;
		}
		$DB->update_records('online_users', ['sess_invalid' => 1], ['sess_id' => $sess_id]);
		return true;
	}

	/**
	 * Get a list of active sessions.
	 *
	 * @param  \pdyn\database\DbDriverInterface $DB An active database connection.
	 * @return array Array of records of active sessions.
	 */
	public static function get_online_users(\pdyn\database\DbDriverInterface &$DB) {
		return $DB->get_records('online_users', ['sess_invalid' => '0'], ['sess_created' => 'DESC']);
	}
}
