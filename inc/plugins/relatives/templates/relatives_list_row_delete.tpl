<form action="relatives.php?action=delete" method="post" class="relatives_inline_form" data-relatives-confirm="{$relatives_row_confirm_message}" data-relatives-confirm-title="{$relatives_row_confirm_title}" data-relatives-confirm-ok="{$lang->relatives_delete}" data-relatives-confirm-cancel="{$lang->relatives_cancel}">
	<input type="hidden" name="id" value="{$relatives_row_id}" />
	<input type="hidden" name="my_post_key" value="{$mybb->post_code}" />
	<input type="submit" class="button" value="{$lang->relatives_delete}" />
</form>
