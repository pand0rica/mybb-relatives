<form action="relatives.php?action=delete_withdraw" method="post" class="relatives_inline_form">
	<input type="hidden" name="id" value="{$relatives_row_delete_request_id}" />
	<input type="hidden" name="my_post_key" value="{$mybb->post_code}" />
	<input type="submit" class="button" value="{$lang->relatives_delete_withdraw}" />
</form>
