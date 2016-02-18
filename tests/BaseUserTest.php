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

namespace pdyn\user\tests;

use \pdyn\base\Exception;

class MockUser extends \pdyn\user\User {
	use \pdyn\testing\AccessibleObjectTrait;
}

/**
 * Test the \pdyn\user\User class.
 *
 * @group pdyn
 * @group pdyn_user
 */
class UserTest extends \pdyn\orm\tests\DataobjectTestcase {
	protected function get_dbschemaclass() {
		return ['\pdyn\user\DbSchema'];
	}

	protected function get_dataobjectclass() {
		return '\pdyn\user\tests\MockUser';
	}

	/**
	 * PHPUnit setup - create temp cache dir.
	 */
	protected function setUp() {
		parent::setUp();
		// Add guest user.
		$timenow = time();
		$guestuser = [
			'id' => \pdyn\user\UserInterface::GUESTID,
			'username' => 'guest',
			'nameshort' => 'Guest',
			'namefull' => 'Guest User',
			'image' => '',
			'deleted' => 0,
			'timecreated' => $timenow,
			'timeupdated' => $timenow,
		];
		$this->DB->insert_record('users', $guestuser);

		$adminuser = [
			'id' => 1,
			'username' => 'admin',
			'nameshort' => 'Admin',
			'namefull' => 'Admin User',
			'image' => '',
			'deleted' => 0,
			'timecreated' => $timenow,
			'timeupdated' => $timenow,
		];
		$this->DB->insert_record('users', $adminuser);
	}

	/**
	 * Tests user preferences functions.
	 *
	 * Tests get_pref and set_pref, as well as successful loading from the database.
	 */
	public function test_prefs() {
		$objectclass = $this->get_dataobjectclass();
		$user = $this->constructobject();
		$user->set(['username' => 'testuser']);
		$user->save();
		$userid = $user->id;

		$this->DB->insert_record('users_preferences', [
			'component' => 'core',
			'preftype' => 'testpref',
			'prefvalue' => 'testprefval',
			'userid' => $user->id,
		]);

		$user = $this->constructobject();
		$user->load($userid);
		$user->load_prefs();

		$prefval = $user->get_pref('core', 'testpref');
		$this->assertEquals('testprefval', $prefval);

		// Test getting a preference that is not set.
		$nullval = $user->get_pref('core', 'missingpref');
		$this->assertEquals(null, $nullval);

		// Test invalid value for preference.
		try {
			$user->set_pref('core', 'testpref', ['testprefval2']);
			$this->assertFalse(true);
		} catch (\Exception $e) {
			$this->assertTrue(true);
		}

		// Test set_pref.
		$user->set_pref('core', 'testpref', 'testprefval2');
		$user->set_pref('core', 'testpref2', 'testprefval3');
		$expected = [
			'core' => [
				'testpref' => 'testprefval2',
				'testpref2' => 'testprefval3',
			],
		];
		$this->assertEquals($expected, $user->prefs);
		$this->assertEquals('testprefval2', $user->get_pref('core', 'testpref'));
		$this->assertEquals('testprefval3', $user->get_pref('core', 'testpref2'));

		// Validate db info.
		$dbrecs = $this->DB->get_records('users_preferences', ['userid' => $user->id]);
		$expected = [
			[
				'id' => '1',
				'userid' => (string)$user->id,
				'component' => 'core',
				'preftype' => 'testpref',
				'prefvalue' => 'testprefval2',
			],
			[
				'id' => '2',
				'userid' => (string)$user->id,
				'component' => 'core',
				'preftype' => 'testpref2',
				'prefvalue' => 'testprefval3',
			]
		];
		unset($dbrecs[0]['timecreated']);
		unset($dbrecs[0]['timeupdated']);
		unset($dbrecs[1]['timecreated']);
		unset($dbrecs[1]['timeupdated']);
		$this->assertEquals($expected, $dbrecs);
	}

	/**
	 * Test search function.
	 */
	public function test_search() {
		$objectclass = $this->get_dataobjectclass();
		$user1 = $this->constructobject();
		$user1->set(['username' => 'aabbcc', 'namefull' => 'aabbcc']);
		$user1->save();

		$user2 = $this->constructobject();
		$user2->set(['username' => 'ccbbaa', 'namefull' => 'ccbbaa']);
		$user2->save();

		$user3 = $this->constructobject();
		$user3->set(['username' => 'ffeeaa', 'namefull' => 'ffeeaa']);
		$user3->save();

		$user4 = $this->constructobject();
		$user4->set(['username' => 'ddeeff', 'namefull' => 'ddeeff']);
		$user4->save();

		$user5 = $this->constructobject();
		$user5->set(['username' => 'aaffdd', 'namefull' => 'aaffdd']);
		$user5->save();
		$this->DB->update_records('users', ['deleted' => 1], ['id' => $user5->id]);

		$results = $objectclass::search($this->DB, 'aa');
		$expected = [
			[
				'id' => (string)$user1->id,
				'namefull' => $user1->namefull,
				'deleted' => (string)(int)$user1->deleted,
				'username' => $user1->username,
				'nameshort' => '',
			],
			[
				'id' => (string)$user2->id,
				'namefull' => $user2->namefull,
				'deleted' => (string)(int)$user2->deleted,
				'username' => $user2->username,
				'nameshort' => '',
			],
			[
				'id' => (string)$user3->id,
				'namefull' => $user3->namefull,
				'deleted' => (string)(int)$user3->deleted,
				'username' => $user3->username,
				'nameshort' => '',
			],
		];
		$this->assertEquals($expected, $results);

		$results = $objectclass::search($this->DB, 'aa', $objectclass::INCLUDE_DELETED);
		$expected = [
			[
				'id' => (string)$user1->id,
				'namefull' => $user1->namefull,
				'deleted' => (string)(int)$user1->deleted,
				'username' => $user1->username,
				'nameshort' => ''
			],
			[
				'id' => (string)$user2->id,
				'namefull' => $user2->namefull,
				'deleted' => (string)(int)$user2->deleted,
				'username' => $user2->username,
				'nameshort' => ''
			],
			[
				'id' => (string)$user3->id,
				'namefull' => $user3->namefull,
				'deleted' => (string)(int)$user3->deleted,
				'username' => $user3->username,
				'nameshort' => ''
			],
			[
				'id' => (string)$user5->id,
				'namefull' => $user5->namefull,
				'deleted' => (string)(int)1,
				'username' => $user5->username,
				'nameshort' => ''
			],
		];
		$this->assertEquals($expected, $results);

		$results = $objectclass::search($this->DB, 'bb');
		$expected = [
			[
				'id' => (string)$user1->id,
				'namefull' => $user1->namefull,
				'deleted' => (string)(int)$user1->deleted,
				'username' => $user1->username,
				'nameshort' => ''
			],
			[
				'id' => (string)$user2->id,
				'namefull' => $user2->namefull,
				'deleted' => (string)(int)$user2->deleted,
				'username' => $user2->username,
				'nameshort' => ''
			],
		];
		$this->assertEquals($expected, $results);

		$results = $objectclass::search($this->DB, 'ee');
		$expected = [
			[
				'id' => (string)$user3->id,
				'namefull' => $user3->namefull,
				'deleted' => (string)(int)$user3->deleted,
				'username' => $user3->username,
				'nameshort' => ''
			],
			[
				'id' => (string)$user4->id,
				'namefull' => $user4->namefull,
				'deleted' => (string)(int)$user4->deleted,
				'username' => $user4->username,
				'nameshort' => ''
			],
		];
		$this->assertEquals($expected, $results);
	}

	/**
	 * Test get_visible_ident function.
	 */
	public function test_get_visible_ident() {
		$user = $this->constructobject();
		$user->set(['username' => 'testuser1', 'namefull' => 'Test User!']);
		$user->save();
		$this->assertEquals('Test User!', $user->get_visible_ident());

		$user2 = $this->constructobject();
		$user2->set(['username' => 'testuser2', 'nameshort' => 'Test']);
		$user2->save();
		$this->assertEquals('Test', $user2->get_visible_ident());

		$user3 = $this->constructobject();
		$user3->set(['username' => 'testuser3']);
		$user3->save();
		$this->assertEquals('User #'.$user3->id, $user3->get_visible_ident());
	}

	/**
	 * Test get_by_ids function.
	 */
	public function test_get_by_ids() {
		$objectclass = $this->get_dataobjectclass();
		$user1 = $this->constructobject();
		$user1->set(['username' => 'testuser1']);
		$user1->save();
		$user2 = $this->constructobject();
		$user2->set(['username' => 'testuser2']);
		$user2->save();
		$user3 = $this->constructobject();
		$user3->set(['username' => 'testuser3']);
		$user3->save();
		$user4 = $this->constructobject();
		$user4->set(['username' => 'testuser4']);
		$user4->save();
		$user5 = $this->constructobject();
		$user5->set(['username' => 'testuser5']);
		$user5->save();

		$actual = $objectclass::get_by_ids($this->DB, [$user1->id, $user3->id]);
		$expected = [$user3->id => $user3, $user1->id => $user1];
		$this->assertEquals($expected, $actual);
	}

	/**
	 * Test delete function.
	 */
	public function test_delete() {
		$objectclass = $this->get_dataobjectclass();

		// Test user 1 and guest can't be deleted.
		foreach ([1] as $userid) {
			$userrec = $this->DB->get_record('users', ['id' => $userid]);
			$this->assertNotEmpty($userrec);
			$user = $this->constructobject();
			$user->load($userid);
			$result = $user->delete();
			$this->assertFalse($result);
			$userrec = $this->DB->get_record('users', ['id' => $userid]);
			$this->assertNotEmpty($userrec);
			$this->assertEquals(0, $userrec['deleted']);
		}

		$user = $this->constructobject();
		$user->set(['username' => 'testuser1']);

		// Test deleting unsaved user doesn't do anything.
		$result = $user->delete();
		$this->assertFalse($result);
		$result = $user->delete(['remove_all_traces' => true]);
		$this->assertFalse($result);

		$user->save();

		// Test soft-delete.
		$userrec = $this->DB->get_record('users', ['id' => $user->id]);
		$this->assertNotEmpty($userrec);
		$this->assertEquals(0, $userrec['deleted']);
		$this->assertFalse($user->deleted);

		$user->delete();

		$userrec = $this->DB->get_record('users', ['id' => $user->id]);
		$this->assertNotEmpty($userrec);
		$this->assertEquals(1, $userrec['deleted']);
		$this->assertTrue($user->deleted);

		/*
		Test remove all traces.
		 */

		// Create user.
		$user = $this->constructobject();
		$user->set(['username' => 'testuser2']);
		$user->save();
		$userid = $user->id;

		// Create data.
		$user->set_pref('core', 'testpref', 'val');

		$this->DB->insert_record('online_users', ['user_id' => $user->id]);

		// Delete the user.
		$user->delete(['remove_all_traces' => true]);

		// Verify no records in the following tables reference the user.
		$rec = $this->DB->get_records('online_users', ['user_id' => $userid]);
		$this->assertEmpty($rec, '*Record present in online_users*');
		$rec = $this->DB->get_records('users_preferences', ['userid' => $userid]);
		$this->assertEmpty($rec, '*Record present in users_preferences*');
		$rec = $this->DB->get_records('users', ['id' => $userid]);
		$this->assertEmpty($rec, '*Record present in users*');
	}

	/**
	 * Test undelete function.
	 */
	public function test_undelete() {
		$user = $this->constructobject();
		$user->set(['username' => 'testuser1']);

		// Test undeleting an unsaved user doesn't do anything.
		$result = $user->undelete();
		$this->assertFalse($result);

		$user->save();

		// Validate the deleted flag is set after deleting.
		$user->delete();
		$this->assertEquals(true, $user->deleted);
		$userrec = $this->DB->get_record('users', ['id' => $user->id]);
		$this->assertNotEmpty($userrec);
		$this->assertEquals(1, $userrec['deleted']);

		// Test undelete.
		$user->undelete();
		$this->assertEquals(false, $user->deleted);
		$userrec = $this->DB->get_record('users', ['id' => $user->id]);
		$this->assertNotEmpty($userrec);
		$this->assertEquals(0, $userrec['deleted']);

		// Test undelete doesn't work on a user that has been deleted with remove_all_traces set.
		$userid = $user->id;
		$user->delete(['remove_all_traces' => true]);
		$userrec = $this->DB->get_record('users', ['id' => $userid]);
		$this->assertEmpty($userrec);
		$result = $user->undelete();
		$this->assertFalse($result);
	}
}
