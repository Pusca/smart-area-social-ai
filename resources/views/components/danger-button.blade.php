<button {{ $attributes->merge(['type' => 'submit', 'class' => 'ui-btn border border-red-200 bg-red-50 text-red-700 hover:bg-red-100']) }}>
    {{ $slot }}
</button>
