<tr>
	<td>{$relatives_row_name}{$relatives_row_wanted_badge}</td>
	<td>{$relatives_row_relationship}</td>
	<td>{$relatives_row_age}</td>
	<td>{$relatives_row_link_status}</td>
	<td>
		<a href="relatives.php?action=edit&amp;id={$relatives_row_id}">{$lang->relatives_edit}</a>
		&nbsp;|&nbsp;
		<form action="relatives.php?action=delete" method="post" style="display:inline">
			<input type="hidden" name="id" value="{$relatives_row_id}" />
			<input type="hidden" name="my_post_key" value="{$mybb->post_code}" />
			<input type="submit" class="relatives_link_button" value="{$lang->relatives_delete}" onclick="return confirm('{$lang->relatives_confirm_delete}');" />
		</form>
	</td>
</tr>
