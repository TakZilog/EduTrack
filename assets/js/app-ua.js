// Marks the page when it is running inside the EduTrack Android app, which is
// a full-screen WebView that sends a custom user agent. App-only CSS can then
// hide things that make no sense there — first of all the "download the app"
// button, since the visitor is already in the app.
//
// Set on <html> by a blocking script in <head>, so the class is present before
// the body paints and the hidden element never flashes.
if (/EduTrackMobile/i.test(navigator.userAgent)) {
  document.documentElement.classList.add('in-app');
}
