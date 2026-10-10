// Board logic shared by the game page and the start page.
// The page defines check(a, b) (called to merge b's cluster into a's, returns {valid, category} or a promise of it, with the category's name when the merge completes it), moved() (called after every click) and categoryDone(button, category).

var size = 0;  // Number of categories, and of items per category
var score = 0;
var mistakes = 0;
var selected = 0;  // The currently selected button, if any.


function deselect() {
  if (selected != 0) {
    selected.style = "";
    selected = 0;
    moved();
  }
}



function stringToLightColor(str) {
  let hash = 0;
  for (let i = 0; i < str.length; i++) {
    hash = str.charCodeAt(i) + ((hash << 5) - hash);
  }

  const h = Math.abs(hash) % 360;
  const s = 70;
  const l = 80 + (Math.abs(hash) % 10);

  const lDev = l / 100;
  const a = (s * Math.min(lDev, 1 - lDev)) / 100;

  const f = (n) => {
    const k = (n + h / 30) % 12;
    const color = lDev - a * Math.max(Math.min(k - 3, 9 - k, 1), -1);
    return Math.round(255 * color).toString(16).padStart(2, "0");
  };

  return `#${f(0)}${f(8)}${f(4)}`;
}



function finishCategory(b, category) {
  b.innerHTML  =  '<b>' + category + '</b>';
  b.disabled = true;
  b.style.background= stringToLightColor(category);
}



function showCluster(b) {
  if (b.cluster.length == 2) {
    b.innerHTML = '<b>' + b.cluster.join('; ') + '</b>';
  } else if (b.cluster.length > 2) {
    b.innerHTML = '<b>' + b.cluster.slice(0, 2).join(', ') + ', ... <span class="red">[' + b.cluster.length + ']</span></b>';
    b.title = b.cluster.join('\n');
  }
}



//Empties a merged word's button, keeping its slot as a hole
function hole(b) {
  b.title = '';
  b.textContent = '';
  b.disabled = true;
  b.classList.add('gone');
}



//words: size * size of them, in board order
function setUpBoard(words) {
  size = Math.sqrt(words.length);
  score = 0;
  mistakes = 0;
  const b = document.getElementById("board");
  b.style.setProperty('--n', size);
  words.forEach((word, i) => {
    const button = document.createElement('button');
    button.textContent = word;
    button.cluster = [word];
    button.index = i;
    button.onclick = async function() {
      if (selected == button) {
        deselect();
        return;
      }
      button.style = "filter: invert(100%)";
      if (selected == 0) {
        selected = button;
        moved();
        return;
      }
      const other = selected;
      selected = 0;
      const {valid, category} = await check(button, other);
      if (valid) {
        other.style = "";
        button.style = "";
        score += 1;
        button.cluster = button.cluster.concat(other.cluster);
        showCluster(button);
        if (category) {
          finishCategory(button, category);
          categoryDone(button, category);
        }
        hole(other);
      } else {
        mistakes += 1;
        for (const x of [button, other]) {
          x.classList.add('shake');
          x.addEventListener('animationend', () => {
            x.classList.remove('shake');
            x.style = "";
          }, { once: true });
        }
      }
      moved();
    };
    b.appendChild(button);
  });
}
