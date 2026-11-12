<?php

use AP\Access;
use function AP\Tests\assert_same;
use function AP\Tests\assert_true;
use function AP\Tests\make_user;

return [
  'test_access_admin_scope_all_and_filter' => function () {
    make_user('administrator', null);
    assert_true(Access::can_access(), 'admin can access');
    assert_same(null, Access::scope(null));
    assert_same('english', Access::scope('english'));
    assert_same(null, Access::scope('bad'));
  },
  'test_scope_ignores_requested_for_subscriber' => function () {
    make_user('subscriber', 'cantonese');
    assert_true(Access::can_access());
    assert_same('cantonese', Access::scope('mandarin'));
    assert_same('cantonese', Access::scope(null));
  },
  'test_unassigned_subscriber_cannot_access' => function () {
    make_user('subscriber', null);
    assert_same(false, Access::can_access());
    assert_same(false, \AP\ap_user_can_view());
    assert_same(false, \AP\ap_user_can_manage());
  },
  'test_access_invalid_meta_treated_as_unassigned' => function () {
    make_user('subscriber', 'klingon');
    assert_same(null, Access::assigned());
    assert_same(false, Access::can_access());
  },
  'test_access_logged_out' => function () {
    wp_set_current_user(0);
    assert_same(false, Access::can_access());
  },
  'test_access_ui_congregation' => function () {
    make_user('administrator', 'english');
    assert_same('mandarin', Access::ui_congregation());
    make_user('subscriber', 'english');
    assert_same('english', Access::ui_congregation());
  },
  'test_access_assigned_subscriber_can_view_and_manage' => function () {
    make_user('subscriber', 'english');
    assert_true(\AP\ap_user_can_view());
    assert_true(\AP\ap_user_can_manage());
  },
];
