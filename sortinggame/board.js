// Board logic shared by the game page and the start page.
// The page defines moved(change) (called after every click, with {action: 'merge', a, b} or {action: 'mistake'} when something changed) and categoryDone(button) (called when a category is complete).

var rand = Math.random;  // The game page replaces it with a seeded generator
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



function finishCategory(b) {
  b.innerHTML  =  '<b>' + b.category + '</b>';
  b.disabled = true;
  b.style.background= stringToLightColor(b.category);
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



function shuffleArray(array) {
  for (let i = array.length - 1; i > 0; i--) {
    const j = Math.floor(rand() * (i + 1));
    [array[i], array[j]] = [array[j], array[i]];
  }
}



//wordlist: (word, category) pairs, size * size of them
function setUpBoard(wordlist) {
  size = Math.sqrt(wordlist.length);
  score = 0;
  mistakes = 0;
  shuffleArray(wordlist);
  const b = document.getElementById("board");
  b.style.setProperty('--n', size);
  wordlist.forEach(([word, category], i) => {
    const button = document.createElement('button');
    button.textContent = word;
    button.category = category;
    button.cluster = [word];
    button.index = i;
    button.onclick = function() {
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
      if (button.category == other.category) {
        other.style = "";
        button.style = "";
        score += 1;
        button.cluster = button.cluster.concat(other.cluster);
        showCluster(button);
        if (button.cluster.length == size) {
          finishCategory(button);
          categoryDone(button);
        }
        hole(other);
        moved({action: 'merge', a: button.index, b: other.index});
      } else {
        mistakes += 1;
        for (const x of [button, other]) {
          x.classList.add('shake');
          x.addEventListener('animationend', () => {
            x.classList.remove('shake');
            x.style = "";
          }, { once: true });
        }
        moved({action: 'mistake'});
      }
    };
    b.appendChild(button);
  });
}
