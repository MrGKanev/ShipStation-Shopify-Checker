<div class="grid gap-5 sm:grid-cols-2">
    <div class="flex flex-col gap-2 sm:col-span-2">
        <label class="text-sm font-medium" for="label">Store name</label>
        <input class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="label" name="label" value="{{ old('label', $store?->label) }}" required>
        @error('label') <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>

    <div class="flex flex-col gap-2">
        <label class="text-sm font-medium" for="slug">Internal slug</label>
        <input class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="slug" name="slug" value="{{ old('slug', $store?->slug) }}" required>
        @error('slug') <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>

    <div class="flex flex-col gap-2">
        <label class="text-sm font-medium" for="shopify_store">Shopify store</label>
        <div class="flex rounded-lg border border-slate-300 bg-white dark:border-slate-700 dark:bg-slate-950">
            <input class="min-w-0 grow rounded-l-lg bg-transparent px-3 py-2" id="shopify_store" name="shopify_store" value="{{ old('shopify_store', $store?->shopify_store) }}" required>
            <span class="border-l border-slate-300 px-3 py-2 text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">.myshopify.com</span>
        </div>
        @error('shopify_store') <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>

    <div class="flex flex-col gap-2 sm:col-span-2">
        <label class="text-sm font-medium" for="shopify_access_token">Shopify access token</label>
        <input class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="shopify_access_token" name="shopify_access_token" type="password" autocomplete="new-password" {{ $store === null ? 'required' : '' }}>
        @if ($store !== null) <p class="text-xs text-slate-500 dark:text-slate-400">Leave blank to keep the current token.</p> @endif
        @error('shopify_access_token') <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>

    <div class="flex flex-col gap-2">
        <label class="text-sm font-medium" for="shipstation_api_key">ShipStation API key</label>
        <input class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="shipstation_api_key" name="shipstation_api_key" type="password" autocomplete="new-password">
        @if ($store !== null) <p class="text-xs text-slate-500 dark:text-slate-400">Leave blank to keep the current key.</p> @endif
        @error('shipstation_api_key') <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>

    <div class="flex flex-col gap-2">
        <label class="text-sm font-medium" for="shipstation_api_secret">ShipStation API secret</label>
        <input class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="shipstation_api_secret" name="shipstation_api_secret" type="password" autocomplete="new-password">
        @if ($store !== null) <p class="text-xs text-slate-500 dark:text-slate-400">Leave blank to keep the current secret.</p> @endif
        @error('shipstation_api_secret') <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>

    <div class="flex flex-col gap-2 sm:col-span-2">
        <label class="text-sm font-medium" for="store_number">ShipStation store number</label>
        <input class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950" id="store_number" name="store_number" value="{{ old('store_number', $store?->store_number) }}">
        @error('store_number') <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
</div>

<div class="mt-6 flex items-center gap-3">
    <button class="rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500" type="submit">Save store</button>
    <a class="text-sm font-semibold text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white" href="{{ route('admin.stores.index') }}">Cancel</a>
</div>
