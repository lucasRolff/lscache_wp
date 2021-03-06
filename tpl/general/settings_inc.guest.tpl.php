<?php
namespace LiteSpeed;
defined( 'WPINC' ) || exit;
?>
	<tr>
		<th>
			<?php $id = Base::O_GUEST; ?>
			<?php $this->title( $id ); ?>
		</th>
		<td>
			<?php $this->build_switch( $id ); ?>
			<div class="litespeed-desc">
				<?php echo __( 'Guest Mode provides an always cacheable landing page on a user\'s first time visit, and then attempts to update cache varies via AJAX.', 'litespeed-cache' ); ?>
				<?php echo __( 'This option can help to correct the cache vary for certain advanced mobile or tablet users.', 'litespeed-cache' ); ?>
				<br /><?php Doc::notice_htaccess(); ?>
			</div>
		</td>
	</tr>

