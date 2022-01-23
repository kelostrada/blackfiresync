<div class="panel">
	<div class="panel-heading">
        {l s='Blackfire Sync' mod='blackfiresync'}
    </div>

    <div class="panel-body" id="blackfire-sync">
        <div class="container">

            <strong>Account ID:</strong> {$account["id"]}

            <select class="form-control" id="bf-category" name="category_id">
                <option value="">--- SELECT CATEGORY ---</option>
                {foreach from=$categories item=category}
                <option value="{$category.id}">{$category.name}</option>
                {/foreach}
            </select>

        </div>
    </div>
</div>
