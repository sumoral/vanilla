<?php
/**
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2016 Vanilla Forums Inc.
 * @license GPLv2
 */

namespace VanillaTests\APIv0;

/**
 * Test some basic Vanilla functionality to make sure nothing is horribly broken.
 */
class SmokeTest extends BaseTest {

    /** @var  int */
    protected static $restrictedCategoryID;

    /**
     * @var array
     */
    protected static $testUser;

    /**
     * Get the ID of the restricted category.
     *
     * @return int
     */
    public function getRestrictedCategoryID() {
        return static::$restrictedCategoryID;
    }

    /**
     * Get the testUser.
     *
     * @return array Returns the testUser.
     */
    public function getTestUser() {
        return self::$testUser;
    }

    /**
     * Set the ID of the restricted category.
     *
     * @param int $categoryID
     * @return $this
     */
    public function setRestrictedCategoryID($categoryID) {
        static::$restrictedCategoryID = $categoryID;
        return $this;
    }

    /**
     * Set the testUser.
     *
     * @param array $testUser The user to set.
     * @return StandardTest Returns `$this` for fluent calls.
     * @see APIv0::queryUserKey()
     */
    public function setTestUser($testUser) {
        static::$testUser = $testUser;
        return $this;
    }

    /**
     * Test registering a user with the basic method.
     */
    public function testRegisterBasic() {
        $this->api()->saveToConfig([
            'Garden.Registration.Method' => 'Basic',
            'Garden.Registration.ConfirmEmail' => false,
            'Garden.Registration.SkipCaptcha' => true
        ]);

        $user = [
            'Name' => 'frank',
            'Email' => 'frank@example.com',
            'Password' => 'frankwantsin',
            'PasswordMatch' => 'frankwantsin',
            'Gender' => 'm',
            'TermsOfService' => true
        ];

        // Register the user.
        $r = $this->api()->post('/entry/register.json', $user);
        $body = $r->getBody();
        $this->assertSame('Basic', $body['Method']);

        // Look for the user in the database.
        $dbUser = $this->api()->queryUserKey($user['Name'], true);
        $this->assertSame($user['Email'], $dbUser['Email']);
        $this->assertSame($user['Gender'], $dbUser['Gender']);

        // Look up the user for confirmation.
        $siteUser = $this->api()->get('/profile.json', ['username' => $user['Name']]);
        $siteUser = $siteUser['Profile'];

        $this->assertEquals($user['Name'], $siteUser['Name']);

        $siteUser['tk'] = $this->api()->getTK($siteUser['UserID']);
        $this->setTestUser($siteUser);
        return $siteUser;
    }



    /**
     * Test adding an admin user.
     */
    public function testAddAdminUser() {
        $system = $this->api()->querySystemUser(true);
        $this->api()->setUser($system);

        $adminUser = [
            'Name' => 'Admin',
            'Email' => 'admin@example.com',
            'Password' => 'adminsecure'
        ];

        // Get the admin roles.
        $adminRole = $this->api()->queryOne("select * from GDN_Role where Name = :name", [':name' => 'Administrator']);
        $this->assertNotEmpty($adminRole);
        $adminUser['RoleID'] = [$adminRole['RoleID']];

        $this->api()->saveToConfig([
            'Garden.Email.Disabled' => true,
        ]);
        $r = $this->api()->post(
            '/user/add.json',
            http_build_query($adminUser)
        );
        $b = $r->getBody();
        $this->assertResponseSuccess($r);

        // Query the user in the database.
        $dbUser = $this->api()->queryUserKey('Admin', true);

        // Query the admin role.
        $userRoles = $this->api()->query("select * from GDN_UserRole where UserID = :userID", [':userID' => $dbUser['UserID']]);
        $userRoleIDs = array_column($userRoles, 'RoleID');
        $this->assertEquals($adminUser['RoleID'], $userRoleIDs);

        $dbUser['tk'] = $this->api()->getTK($dbUser['UserID']);
        return $dbUser;
    }

    /**
     * Test that a category with restricted permissions can be created.
     */
    public function testCreateRestrictedCategory() {
        $r = $this->api()->post('/vanilla/settings/addcategory.json', [
            'Name' => 'Moderators Only',
            'UrlCode' => 'moderators-only',
            'DisplayAs' => 'Discussions',
            'CustomPermissions' => 1,
            'Permission' => http_build_query([
                'Category/PermissionCategoryID/0/32//Vanilla.Comments.Add',
                'Category/PermissionCategoryID/0/32//Vanilla.Comments.Delete',
                'Category/PermissionCategoryID/0/32//Vanilla.Comments.Edit',
                'Category/PermissionCategoryID/0/32//Vanilla.Discussions.Add',
                'Category/PermissionCategoryID/0/32//Vanilla.Discussions.Announce',
                'Category/PermissionCategoryID/0/32//Vanilla.Comments.Add',
                'Category/PermissionCategoryID/0/32//Vanilla.Discussions.Close',
                'Category/PermissionCategoryID/0/32//Vanilla.Discussions.Delete',
                'Category/PermissionCategoryID/0/32//Vanilla.Discussions.Edit',
                'Category/PermissionCategoryID/0/32//Vanilla.Discussions.Sink',
                'Category/PermissionCategoryID/0/32//Vanilla.Discussions.View'
            ])
        ]);

        $body = $r->getBody();
        $category = $body['Category'];
        $this->assertArrayHasKey('CategoryID', $category);

        $this->setRestrictedCategoryID($category['CategoryID']);
    }

    /**
     * Test that a photo can be saved to a user.
     *
     * @param array $admin An admin user with permission to add a photo.
     * @param array $user The user to test against.
     * @depends testAddAdminUser
     * @depends testRegisterBasic
     */
    public function testSetPhoto($admin, $user) {
        $this->api()->setUser($admin);

        $photo = 'http://example.com/u.gif';
        $r = $this->api()->post('/profile/edit.json?userid='.$user['UserID'], ['Photo' => $photo]);

        $dbUser = $this->api()->queryUserKey($user['UserID'], true);
        $this->assertSame($photo, $dbUser['Photo']);
    }

    /**
     * Test an invalid photo URL on a user.
     *
     * @param array $admin The user that will set the photo.
     * @param array $user The user to test against.
     * @depends testAddAdminUser
     * @depends testRegisterBasic
     * @expectedException \Exception
     * @expectedExceptionMessage Invalid photo URL.
     */
    public function testSetInvalidPhoto($admin, $user) {
        $this->api()->setUser($admin);

        $photo = 'javascript: alert("Xss");';
        $r = $this->api()->post('/profile/edit.json?userid='.$user['UserID'], ['Photo' => $photo]);

        $dbUser = $this->api()->queryUserKey($user['UserID'], true);
        $this->assertSame($photo, $dbUser['Photo']);
    }

    /**
     * Test a permission error when adding a photo.
     *
     * @param array $user The user to test against.
     * @depends testRegisterBasic
     */
    public function testSetPhotoPermission($user) {
        $this->api()->setUser($user);

        $dbUser = $this->api()->queryUserKey($user['UserID'], true);

        $photo = 'http://foo.com/bar.png';
        $r = $this->api()->post('/profile/edit.json?userid='.$user['UserID'], ['Photo' => $photo]);

        $dbUser2 = $this->api()->queryUserKey($user['UserID'], true);
        $this->assertNotEquals($photo, $dbUser2['Photo']);
        $this->assertSame($dbUser['Photo'], $dbUser2['Photo']);
    }

    /**
     * Test setting an uploaded photo that isn't a valid URL.
     *
     * @param array $user The user to test against.
     * @depends testRegisterBasic
     */
    public function testSetPhotoPermissionLocal($user) {
        $this->api()->setUser($user);

        $dbUser = $this->api()->queryUserKey($user['UserID'], true);

        // This is a valid upload URL and should be allowed.
        $photo = 'userpics/679/FPNH7GFCMGBA.jpg';
        $this->assertNotEquals($dbUser['Photo'], $photo);
        $r = $this->api()->post('/profile/edit.json?userid='.$user['UserID'], ['Photo' => $photo]);

        $dbUser2 = $this->api()->queryUserKey($user['UserID'], true);
        $this->assertSame($photo, $dbUser2['Photo']);
        $this->assertNotEquals($dbUser['Photo'], $dbUser2['Photo']);
    }

    /**
     * Test that the APIv0 can actually send a correctly formatted user cookie.
     *
     * @depends testRegisterBasic
     */
    public function testUserCookie() {
        $testUser = $this->getTestUser();
        $this->api()->setUser($testUser);
        $profile = $this->api()->get('/profile.json');

        $user = $profile['Profile'];
        $this->assertEquals($testUser['UserID'], $user['UserID']);
    }

    /**
     * Test posting a discussion.
     *
     * @depends testRegisterBasic
     */
    public function testPostDiscussion() {
        $api = $this->api();
        $api->setUser($this->getTestUser());

        $discussion = [
            'CategoryID' => 1,
            'Name' => 'SmokeTest::testPostDiscussion()',
            'Body' => 'Test '.date('r')
        ];

        $r = $api->post(
            '/post/discussion.json',
            $discussion
        );

        $postedDiscussion = $r->getBody();
        $postedDiscussion = $postedDiscussion['Discussion'];
        $this->assertArraySubset($discussion, $postedDiscussion);
    }

    /**
     * Test posting a single comment.
     *
     * @throws \Exception Throws an exception when there are no discussions.
     * @depends testPostDiscussion
     */
    public function testPostComment() {
        $this->api()->setUser($this->getTestUser());

        $discussions = $this->api()->get('/discussions.json')->getBody();
        $discussions = val('Discussions', $discussions);
        if (empty($discussions)) {
            throw new \Exception("There are no discussions to post to.");
        }
        $discussion = reset($discussions);


        $comment = [
            'DiscussionID' => $discussion['DiscussionID'],
            'Body' => 'SmokeTest->testPostComment() '.date('r')
        ];

        $r = $this->api()->post(
            '/post/comment.json',
            $comment
        );

        $postedComment = $r->getBody();
        $postedComment = $postedComment['Comment'];
        $this->assertArraySubset($comment, $postedComment);
    }

    /**
     * Test posting a discussion in a restricted category.
     *
     * @depends testCreateRestrictedCategory
     * @expectedException \Exception
     * @expectedExceptionMessage You do not have permission to post in this category.
     */
    public function testPostRestrictedDiscussion() {
        $categoryID = $this->getRestrictedCategoryID();

        if (!is_numeric($categoryID)) {
            throw new \Exception('Invalid restricted category ID.');
        }

        $api = $this->api();
        $api->setUser($this->getTestUser());

        $discussion = [
            'CategoryID' => $categoryID,
            'Name' => 'SmokeTest::testPostRestrictedDiscussion()',
            'Body' => 'Test '.date('r')
        ];

        $api->post(
            '/post/discussion.json',
            $discussion
        );
    }

    /**
     * Test viewing a restricted category.
     *
     * @depends testCreateRestrictedCategory
     * @expectedException \Exception
     * @expectedExceptionMessage You don't have permission to do that.
     */
    public function testViewRestrictedCategory() {
        $categoryID = $this->getRestrictedCategoryID();

        if (!is_numeric($categoryID)) {
            throw new \Exception('Invalid restricted category ID.');
        }

        $api = $this->api();
        $api->setUser($this->getTestUser());

        $api->get("categories.json?CategoryIdentifier={$categoryID}");
    }
}
