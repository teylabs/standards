<x-layouts.app>
    {{-- <dialog> in a Blade comment is not markup --}}
    <form method="POST" action="/orders">
        @csrf
        <input type="hidden" name="filter" value="open">
        <input type="search" name="q">
        <input :type="$fieldType" name="bound">
        <input type="{{ $fieldType }}" name="echoed">
        <input @class(['w-full', 'border']) type="text" name="note">
        <input {{ $attributes->merge(['type' => 'email']) }} name="email">
        <x-button type="submit">Filter</x-button>
    </form>
    <table>
        <tr><td>{{ '<dialog>' }}</td></tr>
    </table>
    <x-orders.summary />
    <x-orders::badge />
</x-layouts.app>
