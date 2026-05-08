const bus = new EventTarget();

export function on(eventName, handler) {
    bus.addEventListener(eventName, handler);
    return function unsubscribe() {
        bus.removeEventListener(eventName, handler);
    };
}

export function emit(eventName, detail = {}) {
    bus.dispatchEvent(new CustomEvent(eventName, { detail }));
}

export default bus;
