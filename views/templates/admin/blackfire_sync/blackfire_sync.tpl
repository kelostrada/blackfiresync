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
</div>

<div class="panel">
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
                        <th></th>
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
                            {$product["Item-ID"]}
                        </td>
                        <td>
                            {$product["Your Price"]}
                        </td>
                        <td>
                            {$product["Stock Level"]}
                        </td>
                        <td class="blackfire-sync-shop-product">
                            {* <div class="blackfire-sync-shop-product-details">
                                <a href="/asd">
                                    <i class="blackfire-sync-shop-product-details-close material-icons">close</i>
                                </a>
                                <img class="blackfire-sync-shop-product-details-image" height="80" 
                                    src="{$link->getImageLink($product.shop_product.link_rewrite, $product.shop_product.id_image, '')}">
                                <span class="blackfire-sync-shop-product-name">{$product.shop_product.name}</span>
                            </div> *}

                            <form class="form-inline" role="form" type="POST" action="">
                                <input type="hidden" name="controller" value="AdminBlackfireSync" />
                                <input type="hidden" name="token" value="{Tools::getAdminTokenLite('AdminBlackfireSync')}" />
                                <input type="hidden" name="category_id" value="{$categoryID}" />
                                <input type="hidden" name="subcategory_id" value="{$subcategoryID}" />
                                <input type="hidden" name="id_product" value="{$product['ID']}" />

                                <div class="form-row">
                                    <div class="col-md-8">
                                        <input type="text" class="form-control update-shop-product" placeholder="Product ID" 
                                            value="{$product.shop_product.id_product}" name="id_shop_product" size="7" />
                                    </div>
                                    <div class="col-md-4">
                                        <input type="submit" class="btn btn-primary" value="ok"/>
                                    </div>
                                </div>
                            </form>
                        </td>
                    </tr>
                    {/foreach}
                </tbody>
            </table>
        </div>
    </div>
</div>
