<tr>
	<td>
		{$relatives_dr_text}
		<div class="relatives_link_request_meta">{$relatives_dr_date}</div>
	</td>
	<td class="relatives_link_request_actions">
		<form action="relatives.php?action=delete_vote" method="post" class="relatives_inline_form" data-relatives-confirm="{$relatives_dr_confirm_message}" data-relatives-confirm-title="{$relatives_dr_confirm_title}" data-relatives-confirm-ok="{$lang->relatives_delete_vote_confirm}" data-relatives-confirm-cancel="{$lang->relatives_cancel}">
			<input type="hidden" name="id" value="{$relatives_dr_id}" />
			<input type="hidden" name="vote" value="confirm" />
			<input type="hidden" name="my_post_key" value="{$mybb->post_code}" />
			<input type="submit" class="button" value="{$lang->relatives_delete_vote_confirm}" />
		</form>
		<form action="relatives.php?action=delete_vote" method="post" class="relatives_inline_form">
			<input type="hidden" name="id" value="{$relatives_dr_id}" />
			<input type="hidden" name="vote" value="decline" />
			<input type="hidden" name="my_post_key" value="{$mybb->post_code}" />
			<input type="submit" class="button" value="{$lang->relatives_delete_vote_decline}" />
		</form>
	</td>
</tr>
