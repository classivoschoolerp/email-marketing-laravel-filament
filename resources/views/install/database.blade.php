<x-install-layout :step="$step" :title="__('install.database.title')">
    <h2 class="text-lg font-semibold">{{ __('install.database.title') }}</h2>
    <p class="mt-1 text-sm text-gray-600">{{ __('install.database.intro') }}</p>

    <form method="POST" action="{{ route('install.database.store') }}" class="mt-6 space-y-4">
        @csrf

        <div>
            <label for="driver" class="block text-sm font-medium">{{ __('install.database.driver') }}</label>
            <select id="driver" name="driver" class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                @foreach (['mysql', 'mariadb', 'pgsql', 'sqlite'] as $driver)
                    <option value="{{ $driver }}" @selected(old('driver', $values['driver']) === $driver)>{{ $driver }}</option>
                @endforeach
            </select>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="host" class="block text-sm font-medium">{{ __('install.database.host') }}</label>
                <input id="host" name="host" type="text" value="{{ old('host', $values['host']) }}"
                       class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
            </div>
            <div>
                <label for="port" class="block text-sm font-medium">{{ __('install.database.port') }}</label>
                <input id="port" name="port" type="number" value="{{ old('port', $values['port']) }}"
                       class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
            </div>
        </div>

        <div>
            <label for="database" class="block text-sm font-medium">{{ __('install.database.name') }}</label>
            <input id="database" name="database" type="text" required value="{{ old('database', $values['database']) }}"
                   class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
            <p class="mt-1 text-xs text-gray-500">{{ __('install.database.sqlite_hint') }}</p>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="username" class="block text-sm font-medium">{{ __('install.database.username') }}</label>
                <input id="username" name="username" type="text" value="{{ old('username', $values['username']) }}"
                       class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
            </div>
            <div>
                <label for="password" class="block text-sm font-medium">{{ __('install.database.password') }}</label>
                <input id="password" name="password" type="password"
                       class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
            </div>
        </div>

        <div>
            <label for="app_url" class="block text-sm font-medium">{{ __('install.database.app_url') }}</label>
            <input id="app_url" name="app_url" type="url" value="{{ old('app_url', config('app.url')) }}"
                   class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
        </div>

        <button type="submit"
                class="w-full rounded-md bg-emerald-600 px-4 py-2 font-semibold text-white shadow hover:bg-emerald-700">
            {{ __('install.database.submit') }}
        </button>

        <p class="text-xs text-gray-500">{{ __('install.database.migrate_note') }}</p>
    </form>
</x-install-layout>
