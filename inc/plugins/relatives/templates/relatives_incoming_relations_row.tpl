<tr>
	<td>
		{$relatives_in_text}
		<form action="relatives.php?action=relation_edit" method="post" class="relatives_incoming_edit">
			<input type="hidden" name="id" value="{$relatives_in_id}" />
			<input type="hidden" name="return" value="{$relatives_in_return}" />
			<input type="hidden" name="my_post_key" value="{$mybb->post_code}" />
			<label>{$relatives_in_subject} &rarr;
				<select name="relationship_type_id">{$relatives_in_type_options}</select>
			</label>
			<input type="text" name="relationship_custom_label" maxlength="255" value="{$relatives_in_custom}" placeholder="{$lang->relatives_field_relationship_custom}" />
			<input type="submit" class="button" value="{$lang->relatives_incoming_change}" />
		</form>
	</td>
	<td class="relatives_link_request_actions">
		<form action="relatives.php?action=relation_delete" method="post" class="relatives_inline_form" data-relatives-confirm="{$lang->relatives_incoming_confirm_delete}" data-relatives-confirm-ok="{$lang->relatives_incoming_delete}" data-relatives-confirm-cancel="{$lang->relatives_cancel}">
			<input type="hidden" name="id" value="{$relatives_in_id}" />
			<input type="hidden" name="return" value="{$relatives_in_return}" />
			<input type="hidden" name="my_post_key" value="{$mybb->post_code}" />
			<input type="submit" class="button" value="{$lang->relatives_incoming_delete}" />
		</form>
	</td>
</tr>
