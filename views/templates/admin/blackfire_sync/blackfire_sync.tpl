<div class="panel">
	<div class="panel-heading">
        {l s='Blackfire Sync' mod='blackfiresync'}
    </div>

    <div class="panel-body" id="blackfire-sync">
        <div class="container">
            <strong>Account ID:</strong> {$account["id"]}

            <form class="form form-inline" role="form" id="bf-categories-form" type="GET">
                <input type="hidden" name="controller" value="AdminBlackfireSync" />
                <input type="hidden" name="token" value="{Tools::getAdminTokenLite('AdminBlackfireSync')}" />

                <select class="form-control" id="bf-category" name="category_id">
                    <option value="">--- SELECT CATEGORY ---</option>
                    {foreach from=$categories item=category}
                    <option value="{$category.id}" {if $category.id == $categoryID}selected{/if}>{$category.name}</option>
                    {/foreach}
                </select>

                {if $categoryID}
                <select class="form-control" id="bf-subcategory" name="subcategory_id">
                    <option value="">--- SELECT SUBCATEGORY ---</option>
                    {foreach from=$categories[$categoryID]["subcategories"] item=sub}
                    <option value="{$sub.id}" {if $sub.id == $subcategoryID}selected{/if}>{$sub.name}</option>
                    {/foreach}
                </select>
                {/if}

            </form>

        </div>
    </div>
</div>
