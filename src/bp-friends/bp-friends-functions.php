<?php
/**
 * BuddyBoss Connections Functions.
 *
 * Functions are where all the magic happens in BuddyPress. They will
 * handle the actual saving or manipulation of information. Usually they will
 * hand off to a database class for data access, then return
 * true or false on success or failure.
 *
 * @package BuddyBoss\Connections\Functions
 * @since BuddyPress 1.5.0
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Create a new friendship.
 *
 * @since BuddyPress 1.0.0
 *
 * @param int  $initiator_userid ID of the "initiator" user (the user who is
 *                               sending the friendship request).
 * @param int  $friend_userid    ID of the "friend" user (the user whose friendship
 *                               is being requested).
 * @param bool $force_accept     Optional. Whether to force acceptance. When false,
 *                               running friends_add_friend() will result in a connection request.
 *                               When true, running friends_add_friend() will result in an accepted
 *                               friendship, with no notifications being sent. Default: false.
 * @return bool True on success, false on failure.
 */
function friends_add_friend( $initiator_userid, $friend_userid, $force_accept = false ) {

	// You cannot be friends with yourself!
	if ( $initiator_userid == $friend_userid ) {
		return false;
	}

	// Check if already friends, and bail if so.
	if ( friends_check_friendship( $initiator_userid, $friend_userid ) ) {
		return true;
	}

	// Setup the friendship data.
	$friendship                    = new BP_Friends_Friendship();
	$friendship->initiator_user_id = (int) $initiator_userid;
	$friendship->friend_user_id    = (int) $friend_userid;
	$friendship->is_confirmed      = 0;
	$friendship->is_limited        = 0;
	$friendship->date_created      = bp_core_current_time();

	if ( ! empty( $force_accept ) ) {
		$friendship->is_confirmed = 1;
	}

	// Bail if friendship could not be saved (how sad!).
	if ( ! $friendship->save() ) {
		return false;
	}

	// Send notifications.
	if ( empty( $force_accept ) ) {
		$action = 'requested';

		// Update friend totals.
	} else {
		$action = 'accepted';
		friends_update_friend_totals( $friendship->initiator_user_id, $friendship->friend_user_id, 'add' );
	}

	/**
	 * Fires at the end of initiating a new friendship connection.
	 *
	 * This is a variable hook, depending on context.
	 * The two potential hooks are: friends_friendship_requested, friends_friendship_accepted.
	 *
	 * @since BuddyPress 1.0.0
	 *
	 * @param int    $id                ID of the pending friendship connection.
	 * @param int    $initiator_user_id ID of the friendship initiator.
	 * @param int    $friend_user_id    ID of the friend user.
	 * @param object $friendship        BuddyPress Friendship Object.
	 */
	do_action( 'friends_friendship_' . $action, $friendship->id, $friendship->initiator_user_id, $friendship->friend_user_id, $friendship );

	return true;
}

/**
 * Remove a friendship.
 *
 * Will also delete the related "friendship_accepted" activity item.
 *
 * @since BuddyPress 1.0.0
 *
 * @param int $initiator_userid ID of the friendship initiator.
 * @param int $friend_userid    ID of the friend user.
 * @return bool True on success, false on failure.
 */
function friends_remove_friend( $initiator_userid, $friend_userid ) {

	$friendship_id = BP_Friends_Friendship::get_friendship_id( $initiator_userid, $friend_userid );
	$friendship    = new BP_Friends_Friendship( $friendship_id );

	/**
	 * Fires before the deletion of a friendship activity item
	 * for the user who canceled the friendship.
	 *
	 * @since BuddyPress 1.5.0
	 *
	 * @param int $friendship_id    ID of the friendship object, if any, between a pair of users.
	 * @param int $initiator_userid ID of the friendship initiator.
	 * @param int $friend_userid    ID of the friend user.
	 */
	do_action( 'friends_before_friendship_delete', $friendship_id, $initiator_userid, $friend_userid );

	/**
	 * Fires before the friendship connection is removed.
	 *
	 * This hook is misleadingly named - the friendship is not yet deleted.
	 * This is your last chance to do something while the friendship exists.
	 *
	 * @since BuddyPress 1.0.0
	 *
	 * @param int $friendship_id    ID of the friendship object, if any, between a pair of users.
	 * @param int $initiator_userid ID of the friendship initiator.
	 * @param int $friend_userid    ID of the friend user.
	 */
	do_action( 'friends_friendship_deleted', $friendship_id, $initiator_userid, $friend_userid );

	if ( $friendship->delete() ) {
		friends_update_friend_totals( $initiator_userid, $friend_userid, 'remove' );

		/**
		 * Fires after the friendship connection is removed.
		 *
		 * @since BuddyPress 1.8.0
		 *
		 * @param int $initiator_userid ID of the friendship initiator.
		 * @param int $friend_userid    ID of the friend user.
		 */
		do_action( 'friends_friendship_post_delete', $initiator_userid, $friend_userid );

		return true;
	}

	return false;
}

/**
 * Mark a connection request as accepted.
 *
 * Also initiates a "friendship_accepted" activity item.
 *
 * @since BuddyPress 1.0.0
 *
 * @param int $friendship_id ID of the pending friendship object.
 * @return bool True on success, false on failure.
 */
function friends_accept_friendship( $friendship_id ) {

	// Get the friendship data.
	$friendship = new BP_Friends_Friendship( $friendship_id, true, false );

	// Accepting friendship.
	if ( empty( $friendship->is_confirmed ) && BP_Friends_Friendship::accept( $friendship_id ) ) {

		// Bump the friendship counts.
		friends_update_friend_totals( $friendship->initiator_user_id, $friendship->friend_user_id );

		/**
		 * Fires after a friendship is accepted.
		 *
		 * @since BuddyPress 1.0.0
		 *
		 * @param int    $id                ID of the pending friendship object.
		 * @param int    $initiator_user_id ID of the friendship initiator.
		 * @param int    $friend_user_id    ID of the user requested friendship with.
		 * @param object $friendship        BuddyPress Friendship Object.
		 */
		do_action( 'friends_friendship_accepted', $friendship->id, $friendship->initiator_user_id, $friendship->friend_user_id, $friendship );

		return true;
	}

	return false;
}

/**
 * Mark a connection request as rejected.
 *
 * @since BuddyPress 1.0.0
 *
 * @param int $friendship_id ID of the pending friendship object.
 * @return bool True on success, false on failure.
 */
function friends_reject_friendship( $friendship_id ) {
	$friendship = new BP_Friends_Friendship( $friendship_id, true, false );

	if ( empty( $friendship->is_confirmed ) && BP_Friends_Friendship::reject( $friendship_id ) ) {

		/**
		 * Fires after a connection request is rejected.
		 *
		 * @since BuddyPress 1.0.0
		 *
		 * @param int                   $friendship_id ID of the pending friendship.
		 * @param BP_Friends_Friendship $friendships Friendship object. Passed by reference.
		 */
		do_action_ref_array( 'friends_friendship_rejected', array( $friendship_id, &$friendship ) );
		return true;
	}

	return false;
}

/**
 * Withdraw a connection request.
 *
 * @since BuddyPress 1.6.0
 *
 * @param int $initiator_userid ID of the friendship initiator - this is the
 *                              user who requested the friendship, and is doing the withdrawing.
 * @param int $friend_userid    ID of the requested friend.
 * @return bool True on success, false on failure.
 */
function friends_withdraw_friendship( $initiator_userid, $friend_userid ) {
	$friendship_id = BP_Friends_Friendship::get_friendship_id( $initiator_userid, $friend_userid );
	$friendship    = new BP_Friends_Friendship( $friendship_id, true, false );

	if ( empty( $friendship->is_confirmed ) && BP_Friends_Friendship::withdraw( $friendship_id ) ) {

		// @deprecated Since 1.9
		do_action_ref_array( 'friends_friendship_whithdrawn', array( $friendship_id, &$friendship ) );

		/**
		 * Fires after a connection request has been withdrawn.
		 *
		 * @since BuddyPress 1.9.0
		 *
		 * @param int                   $friendship_id ID of the friendship.
		 * @param BP_Friends_Friendship $friendship    Friendship object. Passed by reference.
		 */
		do_action_ref_array( 'friends_friendship_withdrawn', array( $friendship_id, &$friendship ) );

		return true;
	}

	return false;
}

/**
 * Check whether two users are friends.
 *
 * @since BuddyPress 1.0.0
 *
 * @param int $user_id            ID of the first user.
 * @param int $possible_friend_id ID of the other user.
 * @return bool Returns true if the two users are friends, otherwise false.
 */
function friends_check_friendship( $user_id, $possible_friend_id ) {

	if ( 'is_friend' == BP_Friends_Friendship::check_is_friend( $user_id, $possible_friend_id ) ) {
		return true;
	}

	return false;
}

/**
 * Get the friendship status of two friends.
 *
 * Will return 'is_friends', 'not_friends', 'pending' or 'awaiting_response'.
 *
 * @since BuddyPress 1.2.0
 *
 * @param int $user_id            ID of the first user.
 * @param int $possible_friend_id ID of the other user.
 * @return string Friend status of the two users.
 */
function friends_check_friendship_status( $user_id, $possible_friend_id ) {
	global $members_template;

	// Check the BP_User_Query first
	// @see bp_friends_filter_user_query_populate_extras().
	if ( ! empty( $members_template->in_the_loop ) ) {
		if ( isset( $members_template->member->friendship_status ) ) {
			return $members_template->member->friendship_status;
		}
	}

	return BP_Friends_Friendship::check_is_friend( $user_id, $possible_friend_id );
}

/**
 * Get the friend count of a given user.
 *
 * @since BuddyPress 1.2.0
 *
 * @param int $user_id ID of the user whose friends are being counted.
 * @return int Connection count of the user.
 */
function friends_get_total_friend_count( $user_id = 0 ) {
	if ( empty( $user_id ) ) {
		$user_id = ( bp_displayed_user_id() ) ? bp_displayed_user_id() : bp_loggedin_user_id();
	}

	$count = bp_get_user_meta( $user_id, 'total_friend_count', true );
	if ( empty( $count ) ) {
		$count = 0;
	}

	/**
	 * Filters the total friend count for a given user.
	 *
	 * @since BuddyPress 1.2.0
	 *
	 * @param int $count Total friend count for a given user.
	 */
	return apply_filters( 'friends_get_total_friend_count', $count );
}

/**
 * Check whether a given user has any friends.
 *
 * @since BuddyPress 1.0.0
 *
 * @param int $user_id ID of the user whose friends are being checked.
 * @return bool True if the user has friends, otherwise false.
 */
function friends_check_user_has_friends( $user_id ) {
	$friend_count = friends_get_total_friend_count( $user_id );

	if ( empty( $friend_count ) ) {
		return false;
	}

	if ( ! (int) $friend_count ) {
		return false;
	}

	return true;
}

/**
 * Get the ID of two users' friendship, if it exists.
 *
 * @since BuddyPress 1.2.0
 *
 * @param int $initiator_user_id ID of the first user.
 * @param int $friend_user_id    ID of the second user.
 * @return int|null ID of the friendship if found, otherwise false.
 */
function friends_get_friendship_id( $initiator_user_id, $friend_user_id ) {
	return BP_Friends_Friendship::get_friendship_id( $initiator_user_id, $friend_user_id );
}

/**
 * Get the IDs of a given user's friends.
 *
 * @since BuddyPress 1.0.0
 *
 * @param int  $user_id              ID of the user whose friends are being retrieved.
 * @param bool $friend_requests_only Optional. Whether to fetch unaccepted
 *                                   requests only. Default: false.
 * @param bool $assoc_arr            Optional. True to receive an array of arrays keyed as
 *                                   'user_id' => $user_id; false to get a one-dimensional
 *                                   array of user IDs. Default: false.
 * @return array
 */
function friends_get_friend_user_ids( $user_id, $friend_requests_only = false, $assoc_arr = false ) {
	return BP_Friends_Friendship::get_friend_user_ids( $user_id, $friend_requests_only, $assoc_arr );
}

/**
 * Search the friends of a user by a search string.
 *
 * @since BuddyPress 1.0.0
 *
 * @param string $search_terms The search string, matched against xprofile fields (if
 *                             available), or usermeta 'nickname' field.
 * @param int    $user_id      ID of the user whose friends are being searched.
 * @param int    $pag_num      Optional. Max number of friends to return.
 * @param int    $pag_page     Optional. The page of results to return. Default: null (no
 *                             pagination - return all results).
 * @return array|bool On success, an array: {
 *     @type array $friends IDs of friends returned by the query.
 *     @type int   $count   Total number of friends (disregarding
 *                          pagination) who match the search.
 * }. Returns false on failure.
 */
function friends_search_friends( $search_terms, $user_id, $pag_num = 10, $pag_page = 1 ) {
	return BP_Friends_Friendship::search_friends( $search_terms, $user_id, $pag_num, $pag_page );
}

/**
 * Get a list of IDs of users who have requested friendship of a given user.
 *
 * @since BuddyPress 1.2.0
 *
 * @param int $user_id The ID of the user who has received the connection requests.
 * @return array|bool An array of user IDs, or false if none are found.
 */
function friends_get_friendship_request_user_ids( $user_id ) {
	return BP_Friends_Friendship::get_friendship_request_user_ids( $user_id );
}

/**
 * Get a user's most recently active friends.
 *
 * @since BuddyPress 1.0.0
 *
 * @see BP_Core_User::get_users() for a description of return value.
 *
 * @param int    $user_id  ID of the user whose friends are being retrieved.
 * @param int    $per_page Optional. Number of results to return per page.
 *                         Default: 0 (no pagination; show all results).
 * @param int    $page     Optional. Number of the page of results to return.
 *                         Default: 0 (no pagination; show all results).
 * @param string $filter   Optional. Limit results to those matching a search
 *                         string.
 * @return array See {@link BP_Core_User::get_users()}.
 */
function friends_get_recently_active( $user_id, $per_page = 0, $page = 0, $filter = '' ) {
	$friends = bp_core_get_users(
		array(
			'type'         => 'active',
			'per_page'     => $per_page,
			'page'         => $page,
			'user_id'      => $user_id,
			'search_terms' => $filter,
		)
	);

	/**
	 * Filters a user's most recently active friends.
	 *
	 * @since BuddyPress 1.2.0
	 *
	 * @param array $friends {
	 *     @type int   $total_users Total number of users matched by query params.
	 *     @type array $paged_users The current page of users matched by query params.
	 * }
	 */
	return apply_filters( 'friends_get_recently_active', $friends );
}

/**
 * Get a user's friends, in alphabetical order.
 *
 * @since BuddyPress 1.0.0
 *
 * @see BP_Core_User::get_users() for a description of return value.
 *
 * @param int    $user_id  ID of the user whose friends are being retrieved.
 * @param int    $per_page Optional. Number of results to return per page.
 *                         Default: 0 (no pagination; show all results).
 * @param int    $page     Optional. Number of the page of results to return.
 *                         Default: 0 (no pagination; show all results).
 * @param string $filter   Optional. Limit results to those matching a search
 *                         string.
 * @return array See {@link BP_Core_User::get_users()}.
 */
function friends_get_alphabetically( $user_id, $per_page = 0, $page = 0, $filter = '' ) {
	$friends = bp_core_get_users(
		array(
			'type'         => 'alphabetical',
			'per_page'     => $per_page,
			'page'         => $page,
			'user_id'      => $user_id,
			'search_terms' => $filter,
		)
	);

	/**
	 * Filters a user's friends listed in alphabetical order.
	 *
	 * @since BuddyPress 1.2.0
	 *
	 * @return array $friends {
	 *     @type int   $total_users Total number of users matched by query params.
	 *     @type array $paged_users The current page of users matched by query params.
	 * }
	 */
	return apply_filters( 'friends_get_alphabetically', $friends );
}

/**
 * Get a user's friends, in the order in which they joined the site.
 *
 * @since BuddyPress 1.0.0
 *
 * @see BP_Core_User::get_users() for a description of return value.
 *
 * @param int    $user_id  ID of the user whose friends are being retrieved.
 * @param int    $per_page Optional. Number of results to return per page.
 *                         Default: 0 (no pagination; show all results).
 * @param int    $page     Optional. Number of the page of results to return.
 *                         Default: 0 (no pagination; show all results).
 * @param string $filter   Optional. Limit results to those matching a search
 *                         string.
 * @return array See {@link BP_Core_User::get_users()}.
 */
function friends_get_newest( $user_id, $per_page = 0, $page = 0, $filter = '' ) {
	$friends = bp_core_get_users(
		array(
			'type'         => 'newest',
			'per_page'     => $per_page,
			'page'         => $page,
			'user_id'      => $user_id,
			'search_terms' => $filter,
		)
	);

	/**
	 * Filters a user's friends listed from newest to oldest.
	 *
	 * @since BuddyPress 1.2.0
	 *
	 * @param array $friends {
	 *     @type int   $total_users Total number of users matched by query params.
	 *     @type array $paged_users The current page of users matched by query params.
	 * }
	 */
	return apply_filters( 'friends_get_newest', $friends );
}

/**
 * Get the last active date of many users at once.
 *
 * @since BuddyPress 1.0.0
 *
 * @see BP_Friends_Friendship::get_bulk_last_active() for a description of
 *      arguments and return value.
 *
 * @param array $friend_ids See BP_Friends_Friendship::get_bulk_last_active().
 * @return array $user_ids See BP_Friends_Friendship::get_bulk_last_active().
 */
function friends_get_bulk_last_active( $friend_ids ) {
	return BP_Friends_Friendship::get_bulk_last_active( $friend_ids );
}

/**
 * Get a list of friends that a user can invite into this group.
 *
 * Excludes friends that are already in the group, and banned friends if the
 * user is not a group admin.
 *
 * @since BuddyPress 1.0.0
 *
 * @param int $user_id  User ID whose friends to see can be invited. Default:
 *                      ID of the logged-in user.
 * @param int $group_id Group to check possible invitations against.
 * @return mixed False if no friends, array of users if friends.
 */
function friends_get_friends_invite_list( $user_id = 0, $group_id = 0 ) {

	// Default to logged in user id.
	if ( empty( $user_id ) ) {
		$user_id = bp_loggedin_user_id();
	}

	// Only group admins can invited previously banned users.
	$user_is_admin = (bool) groups_is_user_admin( $user_id, $group_id );

	// Assume no friends.
	$friends = array();

	/**
	 * Filters default arguments for list of friends a user can invite into this group.
	 *
	 * @since BuddyPress 1.5.4
	 *
	 * @param array $value Array of default parameters for invite list.
	 */
	$args = apply_filters(
		'bp_friends_pre_get_invite_list',
		array(
			'user_id'  => $user_id,
			'type'     => 'alphabetical',
			'per_page' => 0,
		)
	);

	// User has friends.
	if ( bp_has_members( $args ) ) {

		/**
		 * Loop through all friends and try to add them to the invitation list.
		 *
		 * Exclude friends that:
		 *     1. are already members of the group
		 *     2. are banned from this group if the current user is also not a
		 *        group admin.
		 */
		while ( bp_members() ) :

			// Load the member.
			bp_the_member();

			// Get the user ID of the friend.
			$friend_user_id = bp_get_member_user_id();

			// Skip friend if already in the group.
			if ( groups_is_user_member( $friend_user_id, $group_id ) ) {
				continue;
			}

			// Skip friend if not group admin and user banned from group.
			if ( ( false === $user_is_admin ) && groups_is_user_banned( $friend_user_id, $group_id ) ) {
				continue;
			}

			// Friend is safe, so add it to the array of possible friends.
			$friends[] = array(
				'id'        => $friend_user_id,
				'full_name' => bp_get_member_name(),
			);

		endwhile;
	}

	// If no friends, explicitly set to false.
	if ( empty( $friends ) ) {
		$friends = false;
	}

	/**
	 * Filters the list of potential friends that can be invited to this group.
	 *
	 * @since BuddyPress 1.5.4
	 *
	 * @param array|bool $friends  Array friends available to invite or false for no friends.
	 * @param int        $user_id  ID of the user checked for who they can invite.
	 * @param int        $group_id ID of the group being checked on.
	 */
	return apply_filters( 'bp_friends_get_invite_list', $friends, $user_id, $group_id );
}

/**
 * Get a count of a user's friends who can be invited to a given group.
 *
 * Users can invite any of their friends except:
 *
 * - users who are already in the group
 * - users who have a pending invite to the group
 * - users who have been banned from the group
 *
 * @since BuddyPress 1.0.0
 *
 * @param int $user_id  ID of the user whose friends are being counted.
 * @param int $group_id ID of the group friends are being invited to.
 * @return int $invitable_count Eligible friend count.
 */
function friends_count_invitable_friends( $user_id, $group_id ) {
	return BP_Friends_Friendship::get_invitable_friend_count( $user_id, $group_id );
}

/**
 * Get a total friend count for a given user.
 *
 * @since BuddyPress 1.0.0
 *
 * @param int $user_id Optional. ID of the user whose friendships you are
 *                     counting. Default: displayed user (if any), otherwise logged-in user.
 * @return int Connection count for the user.
 */
function friends_get_friend_count_for_user( $user_id ) {
	return BP_Friends_Friendship::total_friend_count( $user_id );
}

/**
 * Return a list of a user's friends, filtered by a search term.
 *
 * @since BuddyPress 1.0.0
 *
 * @param string $search_terms Search term to filter on.
 * @param int    $user_id      ID of the user whose friends are being searched.
 * @param int    $pag_num      Number of results to return per page. Default: 0 (no
 *                             pagination - show all results).
 * @param int    $pag_page     Number of the page being requested. Default: 0 (no
 *                             pagination - show all results).
 * @return array Array of BP_Core_User objects corresponding to friends.
 */
function friends_search_users( $search_terms, $user_id, $pag_num = 0, $pag_page = 0 ) {

	$user_ids = BP_Friends_Friendship::search_users( $search_terms, $user_id, $pag_num, $pag_page );

	if ( empty( $user_ids ) ) {
		return false;
	}

	$users = array();
	for ( $i = 0, $count = count( $user_ids ); $i < $count; ++$i ) {
		$users[] = new BP_Core_User( $user_ids[ $i ] );
	}

	return array(
		'users' => $users,
		'count' => BP_Friends_Friendship::search_users_count( $search_terms ),
	);
}

/**
 * Has a friendship been confirmed (accepted)?
 *
 * @since BuddyPress 1.0.0
 *
 * @param int $friendship_id The ID of the friendship being checked.
 * @return bool True if the friendship is confirmed, otherwise false.
 */
function friends_is_friendship_confirmed( $friendship_id ) {
	$friendship = new BP_Friends_Friendship( $friendship_id );
	return $friendship->is_confirmed;
}

/**
 * Update user friend counts.
 *
 * Connection counts are cached in usermeta for performance reasons. After a
 * friendship event (acceptance, deletion), call this function to regenerate
 * the cached values.
 *
 * @since BuddyPress 1.0.0
 *
 * @param int    $initiator_user_id ID of the first user.
 * @param int    $friend_user_id    ID of the second user.
 * @param string $status            Optional. The friendship event that's been triggered.
 *                                  'add' will ++ each user's friend counts, while any other string
 *                                  will --.
 */
function friends_update_friend_totals( $initiator_user_id, $friend_user_id, $status = 'add' ) {

	if ( 'add' == $status ) {
		bp_update_user_meta( $initiator_user_id, 'total_friend_count', (int) bp_get_user_meta( $initiator_user_id, 'total_friend_count', true ) + 1 );
		bp_update_user_meta( $friend_user_id, 'total_friend_count', (int) bp_get_user_meta( $friend_user_id, 'total_friend_count', true ) + 1 );
	} else {
		bp_update_user_meta( $initiator_user_id, 'total_friend_count', (int) bp_get_user_meta( $initiator_user_id, 'total_friend_count', true ) - 1 );
		bp_update_user_meta( $friend_user_id, 'total_friend_count', (int) bp_get_user_meta( $friend_user_id, 'total_friend_count', true ) - 1 );
	}
}

/**
 * Remove all friends-related data concerning a given user.
 *
 * Removes the following:
 *
 * - Connections of which the user is a member.
 * - Cached friend count for the user.
 * - Notifications of connection requests sent by the user.
 *
 * @since BuddyPress 1.0.0
 *
 * @param int $user_id ID of the user whose friend data is being removed.
 */
function friends_remove_data( $user_id ) {

	/**
	 * Fires before deletion of friend-related data for a given user.
	 *
	 * @since BuddyPress 1.5.0
	 *
	 * @param int $user_id ID for the user whose friend data is being removed.
	 */
	do_action( 'friends_before_remove_data', $user_id );

	BP_Friends_Friendship::delete_all_for_user( $user_id );

	// Remove usermeta.
	bp_delete_user_meta( $user_id, 'total_friend_count' );

	/**
	 * Fires after deletion of friend-related data for a given user.
	 *
	 * @since BuddyPress 1.0.0
	 *
	 * @param int $user_id ID for the user whose friend data is being removed.
	 */
	do_action( 'friends_remove_data', $user_id );
}
add_action( 'wpmu_delete_user', 'friends_remove_data' );
add_action( 'delete_user', 'friends_remove_data' );
add_action( 'bp_make_spam_user', 'friends_remove_data' );


/** Emails ********************************************************************/

/**
 * Send notifications related to a new friendship request.
 *
 * When a friendship is requested, an email and a BP notification are sent to
 * the user of whom friendship has been requested ($friend_id).
 *
 * @since BuddyPress 1.0.0
 *
 * @param int $friendship_id ID of the friendship object.
 * @param int $initiator_id  ID of the user who initiated the request.
 * @param int $friend_id     ID of the request recipient.
 */
function friends_notification_new_request( $friendship_id, $initiator_id, $friend_id ) {

	if ( true === (bool) apply_filters( 'bb_is_recipient_moderated', false, $friend_id, $initiator_id ) ) {
		return;
	}

	$type_key = 'notification_friends_friendship_request';
	if ( ! bb_enabled_legacy_email_preference() ) {
		$type_key = bb_get_prefences_key( 'legacy', $type_key );
	}

	if ( false === bb_is_notification_enabled( (int) $friend_id, $type_key ) ) {
		return;
	}

	$unsubscribe_args = array(
		'user_id'           => $friend_id,
		'notification_type' => 'friends-request',
	);

	$args = array(
		'tokens' => array(
			'friend-requests.url' => esc_url( bp_core_get_user_domain( $friend_id ) . bp_get_friends_slug() . '/requests/' ),
			'friend.id'           => $friend_id,
			'friendship.id'       => $friendship_id,
			'initiator.id'        => $initiator_id,
			'initiator.url'       => esc_url( bp_core_get_user_domain( $initiator_id ) ),
			'initiator.name'      => bp_core_get_user_displayname( $initiator_id ),
			'unsubscribe'         => esc_url( bp_email_get_unsubscribe_link( $unsubscribe_args ) ),
		),
	);
	bp_send_email( 'friends-request', $friend_id, $args );
}
add_action( 'friends_friendship_requested', 'friends_notification_new_request', 10, 3 );

/**
 * Send notifications related to the acceptance of a connection request.
 *
 * When a connection request is accepted, an email and a BP notification are
 * sent to the user who requested the friendship ($initiator_id).
 *
 * @since BuddyPress 1.0.0
 *
 * @param int $friendship_id ID of the friendship object.
 * @param int $initiator_id  ID of the user who initiated the request.
 * @param int $friend_id     ID of the request recipient.
 */
function friends_notification_accepted_request( $friendship_id, $initiator_id, $friend_id ) {
	$type_key = 'notification_friends_friendship_accepted';
	if ( ! bb_enabled_legacy_email_preference() ) {
		$type_key = bb_get_prefences_key( 'legacy', $type_key );
	}

	if ( false === bb_is_notification_enabled( (int) $initiator_id, $type_key ) ) {
		return;
	}

	$unsubscribe_args = array(
		'user_id'           => $initiator_id,
		'notification_type' => 'friends-request-accepted',
	);

	$args = array(
		'tokens' => array(
			'friend.id'      => $friend_id,
			'friendship.url' => esc_url( bp_core_get_user_domain( $friend_id ) ),
			'friend.name'    => bp_core_get_user_displayname( $friend_id ),
			'friendship.id'  => $friendship_id,
			'initiator.id'   => $initiator_id,
			'unsubscribe'    => esc_url( bp_email_get_unsubscribe_link( $unsubscribe_args ) ),
		),
	);
	bp_send_email( 'friends-request-accepted', $initiator_id, $args );
}
add_action( 'friends_friendship_accepted', 'friends_notification_accepted_request', 10, 3 );
