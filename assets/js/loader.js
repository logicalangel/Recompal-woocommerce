function initRecompal(shopID, userID, name, first_name, last_name, email) {
  var script = document.createElement("script");
  script.src = "https://app.recompal.com/recompal.bundle.js";
  script.onload = function () {
    window.WidgetIndex.default(shopID, {
      id: userID,
      name: name,
      first_name: first_name,
      last_name: last_name,
      email: email,
    });
  };
  document.head.appendChild(script);
}

function getCookie(cname) {
  let name = cname + "=";
  let decodedCookie = decodeURIComponent(document.cookie);
  let ca = decodedCookie.split(";");
  for (let i = 0; i < ca.length; i++) {
    let c = ca[i];
    while (c.charAt(0) == " ") {
      c = c.substring(1);
    }
    if (c.indexOf(name) == 0) {
      return c.substring(name.length, c.length);
    }
  }
  return "";
}
