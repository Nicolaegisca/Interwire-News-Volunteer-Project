function updateClock() {
    const elements = document.querySelectorAll(".iw-time");

    elements.forEach((el) => {
        try {
            const tz = el.dataset.tz;

            const time = new Date().toLocaleTimeString("en-GB", {
                timeZone: tz,
                hour: "2-digit",
                minute: "2-digit",
                hour12: false
            });

            el.textContent = time;
        } catch (error) {
            el.textContent = "--:--";
        }
    });
}

document.addEventListener("DOMContentLoaded", () => {
    updateClock();
    setInterval(updateClock, 30000);
});
