<tr>
	<td>{$lang->relatives_link_request_text}</td>
	<td>
		<form action="relatives.php?action=link_accept" method="post" style="display:inline">
			<input type="hidden" name="id" value="{$relatives_lr_id}" />
			<input type="hidden" name="my_post_key" value="{$mybb->post_code}" />
			<input type="submit" class="relatives_link_button" value="{$lang->relatives_link_accept}" />
		</form>
		<form action="relatives.php?action=link_decline" method="post" style="display:inline">
			<input type="hidden" name="id" value="{$relatives_lr_id}" />
			<input type="hidden" name="my_post_key" value="{$mybb->post_code}" />
			<input type="submit" class="relatives_link_button" value="{$lang->relatives_link_decline}" />
		</form>
	</td>
</tr>
