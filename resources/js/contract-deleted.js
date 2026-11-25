/**
 * A contract page whose contract was deleted by someone else (D19) answers every Livewire request with 404: the
 * components can no longer load it. The page normally hears about the deletion live (ContractDeleted); when it
 * didn't (no WebSocket, a missed message), turn that 404 into the page's "This contract has been deleted" dialog
 * instead of Livewire's error modal. Other pages keep Livewire's default handling.
 */
function register() {
    window.Livewire.interceptRequest(({ onError }) => {
        onError(({ response, preventDefault }) => {
            if (response.status === 404 && document.querySelector('[data-contract-deleted]')) {
                preventDefault();
                window.dispatchEvent(new CustomEvent('akord-contract-gone'));
            }
        });
    });
}

if (window.Livewire) {
    register();
} else {
    document.addEventListener('livewire:init', register, { once: true });
}
