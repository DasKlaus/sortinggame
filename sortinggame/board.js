// Board logic shared by the game page and the start page; the feedback view uses its helpers esc and stringToLightColor.
// The page defines check(a, b) (called to merge b's cluster into a's, returns {valid, category, stale, link, swap} or a promise of it, with the category's name when the merge completes it, and stale when it came too late to count;
// in a chain the link's category, swap when b's cluster comes first, and the category closing the cycle when the chain is finished), moved() (called after every click) and categoryDone(button, category).

var size = 0;  // Number of columns: of categories, and of items per category, when sorting
var full = 0;  // Length of a complete cluster: size when sorting, all words in a chain
var chain = false;  // Chain mode: clusters show their ends, and the categories linking their words in the popout
var score = 0;
var mistakes = 0;
var selected = 0;  // The currently selected button, if any.


function deselect() {
  if (selected != 0) {
    selected.style.filter = "";
    selected = 0;
    moved();
  }
}



const esc = s => s.replace(/[&<>"]/g, c => '&#' + c.charCodeAt(0) + ';');



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
  if (!chain) b.innerHTML  =  '<b>' + category + '</b>';
  b.category = category;
  b.style.background = stringToLightColor(category);
  b.style.color = '#000';
}



function showCluster(b) {
  if (b.cluster.length>1) {
	  b.classList.add('cluster');
	  b.style.setProperty('--fill', b.cluster.length / full);
	  b.title = 'show all items by clicking the number or right-clicking';
  }
  b.innerHTML = (chain ? b.cluster[0] + (b.cluster.length>1 ? ' … ' + b.cluster.at(-1) : '') : b.cluster.join('; ')) + (b.cluster.length>1 ? '<span class="size">' + b.cluster.length + '</span>' : '');
}



//The popout shows a cluster's words, in a chain with the categories linking them, and its category when finished; any click closes it
const popout = document.createElement('div');
popout.id = 'popout';
popout.hidden = true;
popout.onclick = () => popout.hidden = true;
popout.append(document.createElement('div'));
document.body.append(popout);

function showPopout(b) {
  const link = c => '<i>— ' + c + ' —</i>';
  let html = b.cluster.map((w, i) => (chain && i ? link(b.links[i - 1]) : '') + '<span>' + w + '</span>').join('');
  if (b.category) html = chain ? html + link(b.category) + '<span>' + b.cluster[0] + '</span>' : '<b>' + b.category + '</b>' + html;
  openPopout(html);
}

function openPopout(html) {
  popout.firstChild.innerHTML = html;
  popout.hidden = false;
}



//Empties a merged word's button, keeping its slot as a hole
function hole(b) {
  b.textContent = '';
  b.disabled = true;
  b.classList.add('gone');
}



//words: size * size of them when sorting, in board order
function setUpBoard(words) {
  size = Math.ceil(Math.sqrt(words.length));
  full = chain ? words.length : size;
  score = 0;
  mistakes = 0;
  const b = document.getElementById("board");
  b.style.setProperty('--n', size);
  words.forEach((word, i) => {
    const button = document.createElement('button');
    button.textContent = word;
    button.cluster = [word];
    button.links = [];
    button.index = i;
    button.oncontextmenu = e => {
      if (button.cluster.length < 2) return;
      e.preventDefault();
      showPopout(button);
    };
    button.onclick = async function(e) {
      if (button.category || e.target.className == 'size') return showPopout(button);
      if (button.pending) return;
      if (selected == button) {
        deselect();
        return;
      }
      button.style.filter = "invert(100%)";
      if (selected == 0) {
        selected = button;
        moved();
        return;
      }
      const other = selected;
      selected = 0;
      button.pending = other.pending = true;
      const {valid, category, stale, link, swap} = await check(button, other);
      button.pending = other.pending = false;
      if (valid) {
        other.style.filter = "";
        button.style.filter = "";
        score += 1;
        const [keep, gone] = swap ? [other, button] : [button, other];
        keep.cluster = keep.cluster.concat(gone.cluster);
        keep.links = keep.links.concat([link], gone.links);
        showCluster(keep);
        if (category) {
          finishCategory(keep, category);
          categoryDone(keep, category);
        }
        hole(gone);
      } else {
        if (!stale) mistakes += 1;
        for (const x of [button, other]) {
          x.classList.add('shake');
          x.addEventListener('animationend', () => {
            x.classList.remove('shake');
            if (x != selected) x.style.filter = "";
          }, { once: true });
        }
      }
      moved();
    };
    b.appendChild(button);
  });
}
