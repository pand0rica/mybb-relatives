<tr>
	<td>
		{$relatives_lr_text}
		<div class="relatives_link_request_meta">{$relatives_lr_tree_link}</div>
	</td>
	<td class="relatives_link_request_actions">
		<form action="relatives.php?action=link_accept" method="post" style="display:inline">
			<input type="hidden" name="id" value="{$relatives_lr_id}" />
			<input type="hidden" name="my_post_key" value="{$mybb->post_code}" />
			<input type="submit" class="button" value="{$lang->relatives_link_accept}" />
		</form>
		<form action="relatives.php?action=link_decline" method="post" style="display:inline">
			<input type="hidden" name="id" value="{$relatives_lr_id}" />
			<input type="hidden" name="my_post_key" value="{$mybb->post_code}" />
			<input type="submit" class="relatives_link_button" value="{$lang->relatives_link_decline}" />
		</form>
	</td>
</tr>
