<div class="panel">
	<div class="panel-heading">
        {l s='Blackfire Sync' mod='blackfiresync'}
    </div>

    <div class="panel-body" id="blackfire-sync-categories">
        <div class="container">
            <strong>Account:</strong> {$account["email"]} | {$account["name"]} 

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

<div class="panel">
    <div class="panel-body" id="blackfire-sync-products">
        <table class="table" id="blackfire-sync-products-table" data-link="{$link->getAdminLink( 'AdminBlackfireSync' )}">
            <thead>
                <tr>
                    <th>Image</th>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Reference</th>
                    <th>Date</th>
                    <th>Deadline</th>
                    <th>Price</th>
                    <th>Preordered</th>
                    <th>Stock</th>
                    <th>Status</th>
                    <th>Force?</th>
                    <th style="width: 200px;"></th>
                </tr>
            </thead>
            <tbody>
                {foreach from=$products item=product}
                <tr>
                    <td>
                        <a href="https://www.blackfire.eu/en-gb/{$product["id"]}" target="_blank">
                            <img height="80" src="{$product["image_small"]}">
                        </a>
                    </td>
                    <td>
                        {$product["id"]}
                    </td>
                    <td>
                        {$product["name"]} - {$product["ean"]}
                    </td>
                    <td>
                        {$product["ref"]}
                    </td>
                    <td>
                        {date_create_from_format("d/m/Y", $product["release_date"])|date_format:"%Y-%m-%d"}
                    </td>
                    <td>
                        {date_create_from_format("d/m/Y", $product["preorder_deadline"])|date_format:"%Y-%m-%d"}
                    </td>
                    <td>
                        {$product["price"]}
                    </td>
                    <td>
                        {$product["in_preorder"]}
                    </td>
                    <td>
                        {$product["stock"]}
                    </td>
                    <td>
                        {$product["status"]}
                    </td>
                    <td>
                        <form class="form-inline blackfire-sync-product-ignore-deadline-form" role="form" type="POST" action="">
                            <input type="hidden" name="controller" value="AdminBlackfireSync" />
                            <input type="hidden" name="token" value="{Tools::getAdminTokenLite('AdminBlackfireSync')}" />
                            <input type="hidden" name="category_id" value="{$categoryID}" />
                            <input type="hidden" name="subcategory_id" value="{$subcategoryID}" />
                            <input type="hidden" name="id_product" value="{$product['id']}" />
                            <input type="hidden" name="action" value="force" />

                            {if $product.shop_product}
                            <input type="checkbox" name="ignore_deadline" 
                                {if $product.shop_product.ignore_deadline}checked{/if} />
                            {/if}
                        </form>
                    </td>
                    <td class="blackfire-sync-shop-product">
                        <form class="form-inline" role="form" type="POST" action="">
                            <input type="hidden" name="controller" value="AdminBlackfireSync" />
                            <input type="hidden" name="token" value="{Tools::getAdminTokenLite('AdminBlackfireSync')}" />
                            <input type="hidden" name="category_id" value="{$categoryID}" />
                            <input type="hidden" name="subcategory_id" value="{$subcategoryID}" />
                            <input type="hidden" name="id_product" value="{$product['id']}" />

                            <div class="blackfire-sync-shop-product-options">
                                    <input type="text" class="form-control update-shop-product" placeholder="Product ID" 
                                        {if $product.shop_product}value="{$product.shop_product.id_product}"{/if} 
                                        name="id_shop_product" size="7" />
                                    <input type="submit" class="btn btn-primary" name="action" value="ok"/>
                                    {if $product.shop_product}
                                        <input type="submit" class="btn btn-danger" name="action" value="x"/>
                                    {/if}
                                    {if !$product.shop_product}
                                        <input type="submit" class="btn btn-success" name="action" value="new" formtarget="_blank"/>
                                    {/if}
                            </div>
                        </form>
                    </td>
                </tr>
                {/foreach}
            </tbody>
        </table>
    </div>
</div>
