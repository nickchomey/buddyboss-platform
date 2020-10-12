<?php
$listing_class  = '';
$attachment_id  = bp_get_media_attachment_id();
$attachment_url = '';
$media_id       = bp_get_media_id();
$filename       = basename( get_attached_file( $attachment_id ) );
$photo_title    = '';
$media_type     = '';
if ( $attachment_id ) {
	$attachment_url = wp_get_attachment_url( $attachment_id );
	$listing_class  = 'ac-media-list';
	$media_type     = 'photos';
	$photo_title    = bp_get_media_title();
}

$class = ''; // used.
if ( $attachment_id && bp_get_media_activity_id() ) {
	$class = ''; // used.
}
$link = trailingslashit( bp_core_get_user_domain( bp_get_media_user_id() ) . bp_get_media_slug() );
?>

<div class="bp-search-ajax-item bboss_ajax_search_media search-media-list">
	<a href="">
		<div class="item">
			<div class="media-album_items ac-album-list">
				<div class="media-album_thumb">
					<a href="<?php echo esc_url( $albums_link ); ?>">
						<img src="https://picsum.photos/400/270" alt="<?php echo esc_html( $photo_title ); ?>" />
					</a>
				</div>

				<div class="media-album_details">
					<a class="media-album_name " href="<?php echo esc_url( $albums_link ); ?>">
						<span><?php echo esc_html( $photo_title ); ?></span>
					</a>
				</div>

				<div class="media-album_modified">
					<div class="media-album_details__bottom">
						<span class="media-album_date"><?php bp_media_date_created(); ?></span>
						<?php
							if ( ! bp_is_user() ) {
								?>
								<span class="media-album_author"><?php esc_html_e( 'by ', 'buddyboss' ); ?>
								<a href="<?php echo trailingslashit( bp_core_get_user_domain( bp_get_media_user_id() ) . bp_get_media_slug() ); ?>"><?php bp_media_user_id(); ?></a></span>
								<?php
							}
						?>
					</div>
				</div>
				
				<div class="media-album_group">
					<div class="media-album_details__bottom">
						<?php
						if ( bp_is_active( 'groups' ) ) {
							$group_id = bp_get_media_group_id();
							if ( $group_id > 0 ) {
								// Get the group from the database.
								$group 		  = groups_get_group( $group_id );
								$group_name   = isset( $group->name ) ? bp_get_group_name( $group ) : '';
								$group_link   = sprintf( '<a href="%s" class="bp-group-home-link %s-home-link">%s</a>', esc_url( $link ), esc_attr( bp_get_group_slug( $group ) ), esc_html( bp_get_group_name( $group ) ) );
								$group_status = bp_get_group_status( $group );
								?>
								<span class="media-album_group_name"><?php echo wp_kses_post( $group_link ); ?></span>
								<span class="media-album_status"><?php echo ucfirst( $group_status ); ?></span>
								<?php
							} else {
								?>
								<span class="media-album_group_name"> </span>
								<?php
							}
						}
							?>
					</div>
				</div>

				<div class="media-album_visibility">
					<div class="media-album_details__bottom">
						<?php
							if ( bp_is_active( 'groups' ) ) {
								$group_id = bp_get_media_group_id();
								if ( $group_id > 0 ) {
								?>
									<span class="bp-tooltip" data-bp-tooltip-pos="left" data-bp-tooltip="<?php esc_attr_e( 'Based on group privacy', 'buddyboss' ); ?>">
										<?php bp_media_privacy(); ?>
									</span>
									<?php
								} else {
								?>
									<span id="privacy-<?php echo esc_attr( bp_get_media_id() ); ?>">
										<?php bp_media_privacy(); ?>
									</span>
								<?php
								}
							} else {
							?>
								<span>
									<?php bp_media_privacy(); ?>
								</span>
							<?php
							}
						?>
					</div>
				</div>

			</div>
		</div>
	</a>
</div>
