<div class="panel">
	<div class="panel-heading">
        {l s='Blackfire Sync' mod='blackfiresync'}
    </div>

    <div class="panel-body" id="blackfire-sync-categories">
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

    <div class="panel-body" id="blackfire-sync-products">
        <div class="container">
            <table class="table" id="blackfire-sync-products" data-link="{$link->getAdminLink( 'AdminBlackfireSync' )}">
                <thead>
                    <tr>
                        <th>Image</th>
                        <th>Name</th>
                        <th>Reference</th>
                        <th>Price</th>
                        <th>Stock</th>
                    </tr>
                </thead>
                <tbody>
                    {foreach from=$products item=product}
                    <tr>
                        <td><img height="80" src="{$product["Image URL"]}"></td>
                        <td>
                            [{$product["ID"]}] {$product["Name"]} - {$product["EAN"]}
                        </td>
                        <td>
                            <span>{$product["Item-ID"]}</span>
                        </td>
                        <td>
                            {$product["Your Price"]} / {$product["Base Price"]} EUR
                        </td>
                        <td>
                            {$product["Stock Level"]}
                        </td>
                    </tr>
                    {/foreach}
                </tbody>
            </table>
        </div>
    </div>
</div>
