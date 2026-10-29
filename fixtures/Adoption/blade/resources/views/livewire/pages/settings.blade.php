<?php

use function Livewire\Volt\{state};

state(['banner' => '<dialog>']);

?>

<div>
    <table><tr><td>Volt settings</td></tr></table>
    <x-button wire:click="save">Save</x-button>
</div>
