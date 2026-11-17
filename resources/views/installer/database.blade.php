<x-layouts.installer :step="$step" heading="Application and database"
    subheading="The connection is tested before you continue. Existing data in the database is kept — tables are only added.">
    <form method="POST" action="{{ route('install.database') }}" class="space-y-6"
          x-data="{ driver: @js(old('driver', $db['driver'])), redis: @js((bool) old('use_redis', $redis !== null)) }">
        @csrf

        <section class="space-y-4">
            <h2 class="text-sm font-semibold">Application</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form.field label="Application name" for="app_name" required>
                    <x-ui.form.input name="app_name" :value="$app['name']" required maxlength="100" />
                </x-ui.form.field>
                <x-ui.form.field label="Application URL" for="app_url" required hint="The address people will use, e.g. https://billing.example.com">
                    <x-ui.form.input name="app_url" type="url" :value="$app['url']" required />
                </x-ui.form.field>
            </div>
        </section>

        <section class="space-y-4">
            <h2 class="text-sm font-semibold">Database</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form.field label="Database type" for="driver" required>
                    <x-ui.form.select name="driver" :options="$drivers" :value="$db['driver']" x-model="driver" />
                </x-ui.form.field>
                <div class="hidden sm:block"></div>

                <x-ui.form.field label="Host" for="host" required x-show="driver !== 'sqlite'">
                    <x-ui.form.input name="host" :value="$db['host'] ?? ''" placeholder="127.0.0.1" />
                </x-ui.form.field>
                <x-ui.form.field label="Port" for="port" required x-show="driver !== 'sqlite'">
                    <x-ui.form.input name="port" type="number" :value="$db['port'] ?? 3306" min="1" max="65535" />
                </x-ui.form.field>

                <x-ui.form.field label="Database name" for="database" required>
                    <x-ui.form.input name="database" :value="$db['database'] ?? ''" required autocomplete="off" />
                </x-ui.form.field>
                <x-ui.form.field label="Username" for="username" required x-show="driver !== 'sqlite'">
                    <x-ui.form.input name="username" :value="$db['username'] ?? ''" autocomplete="off" />
                </x-ui.form.field>

                <x-ui.form.field label="Password" for="password" x-show="driver !== 'sqlite'"
                    :hint="isset($db['password']) ? 'Leave blank to keep the password entered before.' : 'The database user needs CREATE, ALTER, INDEX, SELECT, INSERT, UPDATE, DELETE.'">
                    <x-ui.form.input name="password" type="password" autocomplete="new-password" />
                </x-ui.form.field>
            </div>
        </section>

        <section class="space-y-4">
            <h2 class="text-sm font-semibold">Cache, sessions and queues</h2>
            @if ($redisAvailable)
                <x-ui.form.checkbox name="use_redis" label="Use Redis (recommended for production)" :checked="$redis !== null" x-model="redis" />
            @else
                <x-ui.alert tone="info">
                    The PHP redis extension is not installed, so the database will be used for cache, sessions and queues.
                    This works; install the extension later to switch to Redis.
                </x-ui.alert>
            @endif
            <p class="muted text-xs" x-show="! redis">Without Redis the database is used for cache, sessions and queues.</p>

            @if ($redisAvailable)
                <div class="grid gap-4 sm:grid-cols-3" x-show="redis" x-cloak>
                    <x-ui.form.field label="Redis host" for="redis_host">
                        <x-ui.form.input name="redis_host" :value="$redis['host'] ?? '127.0.0.1'" />
                    </x-ui.form.field>
                    <x-ui.form.field label="Redis port" for="redis_port">
                        <x-ui.form.input name="redis_port" type="number" :value="$redis['port'] ?? 6379" min="1" max="65535" />
                    </x-ui.form.field>
                    <x-ui.form.field label="Redis password" for="redis_password" hint="Optional">
                        <x-ui.form.input name="redis_password" type="password" autocomplete="new-password" />
                    </x-ui.form.field>
                </div>
            @endif
        </section>

        <div class="flex justify-between gap-2">
            <x-ui.button variant="secondary" :href="route('install.requirements')">Back</x-ui.button>
            <x-ui.button type="submit">Test connection and continue</x-ui.button>
        </div>
    </form>
</x-layouts.installer>
