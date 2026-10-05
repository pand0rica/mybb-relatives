<tr>
	<td>{$relatives_row_name}</td>
	<td>{$relatives_row_relationship}</td>
	<td>{$relatives_row_age}</td>
	<td class="relatives_status_badges">{$relatives_row_wanted_badge}{$relatives_row_link_status}{$relatives_row_delete_status}</td>
	<td class="relatives_row_actions">
		<form action="relatives.php" method="get" class="relatives_inline_form">
			<input type="hidden" name="action" value="edit" />
			<input type="hidden" name="id" value="{$relatives_row_id}" />
			<input type="submit" class="button" value="{$lang->relatives_edit}" />
		</form>
		<form action="relatives.php?action=delete" method="post" class="relatives_inline_form" data-relatives-confirm="{$relatives_row_confirm_message}" data-relatives-confirm-title="{$relatives_row_confirm_title}" data-relatives-confirm-ok="{$lang->relatives_delete}" data-relatives-confirm-cancel="{$lang->relatives_cancel}">
			<input type="hidden" name="id" value="{$relatives_row_id}" />
			<input type="hidden" name="my_post_key" value="{$mybb->post_code}" />
			<input type="submit" class="button" value="{$lang->relatives_delete}" />
		</form>
	</td>
</tr>
